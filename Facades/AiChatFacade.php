<?php
namespace axenox\GenAI\Facades;

use axenox\GenAI\Common\AiPrompt;
use axenox\GenAI\Exceptions\AiPromptError;
use axenox\GenAI\Interfaces\AiPromptInterface;
use exface\Core\CommonLogic\Filesystem\InMemoryFile;
use exface\Core\Exceptions\Facades\FacadeRoutingError;
use exface\Core\Exceptions\UnexpectedValueException;
use exface\Core\Facades\AbstractHttpFacade\Middleware\AuthenticationMiddleware;
use exface\Core\Facades\AbstractHttpFacade\Middleware\DataUrlParamReader;
use exface\Core\Facades\AbstractHttpFacade\Middleware\JsonBodyParser;
use exface\Core\Facades\AbstractHttpFacade\Middleware\TaskReader;
use axenox\GenAI\Factories\AiFactory;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use exface\Core\Facades\AbstractHttpFacade\AbstractHttpFacade;
use GuzzleHttp\Psr7\Response;
use exface\Core\DataTypes\StringDataType;

/**
 * Allows to chat with AI agents defined in the meta model using an OpenAI style API
 * 
 * ## Examples
 * 
 * `POST api/aichat/exface.Core.SqlFilteringAgent/completions?object=exface.Core.USER`
 * 
 * Body:
 * 
 * ```
 * {
 *  "prompt": [
 *   "Show all users added in the past two moths"
 *  ],
 *  "temperature": 0,
 *  "n": 1
 * }
 * 
 * ```
 * 
 * @author Andrej Kabachnik
 *
 */
class AiChatFacade extends AbstractHttpFacade
{
    const REQUEST_ATTR_TASK = 'task';

    protected function createResponse(ServerRequestInterface $request) : ResponseInterface
    {
        $uri = $request->getUri();
        $path = $uri->getPath();
        $headers = $this->buildHeadersCommon();
        // api/aichat/exface.Core.SqlFilterAgent/completions -> exface.Core.SqlFilterAgent/completions
        $pathInFacade = StringDataType::substringAfter($path, $this->getUrlRouteDefault() . '/');
        // exface.Core.SqlFilterAgent/completions -> exface.Core.SqlFilterAgent, completions
        list($agentSelector, $pathInFacade) = explode('/', $pathInFacade, 2);
        $pathInFacade = mb_strtolower($pathInFacade);
        $normalizedRequest = $this->normalizeDeepChatRequest($request);
        $inMemoryFiles = $this->createInMemoryFiles($normalizedRequest['files']);
        
        
        try{
            $prompt = $request->getAttribute(self::REQUEST_ATTR_TASK);
            if(!$prompt instanceof AiPromptInterface){
                throw new UnexpectedValueException("Request not delivered a AI Prompt");
            }
            if ($pathInFacade === 'deepchat') {
                $this->logDeepChatRequest($normalizedRequest);
                $this->applyDeepChatRequestToPrompt($prompt, $normalizedRequest);
            }
            $prompt->setFiles($inMemoryFiles);
            $agent = $this->findAgent($agentSelector);
            $response = $agent->handle($prompt);
        // Do the routing here
            switch (true) {     
                case $pathInFacade === 'completions':

                    $responseCode = 200;
                    $headers['content-type'] = 'application/json';
                    $body = json_encode($response->toArray(), JSON_UNESCAPED_UNICODE);
                    break;
                // Deepchat format - see https://deepchat.dev/docs/connect#Response
                case $pathInFacade === 'deepchat':

                    $responseCode = 200;
                    $headers['content-type'] = 'application/json';
                    $body = json_encode([
                            'text' => $response->getMessage(),
                            'conversation'=> $response->getConversationId(),
                            'additionalMessages' => $response->getStatusMessages()
                        ]
                        , JSON_UNESCAPED_UNICODE
                    );
                    break;
                default:
                    throw new FacadeRoutingError('Route "' . $pathInFacade . '" not found!');
            }
            return new Response(($responseCode ?? 404), $headers, Utils::streamFor($body ?? ''));
        }
        catch(\Throwable $e){
            $this->getWorkbench()->getLogger()->logException($e);
            return $this->createResponseFromError($e, $request);
        } 
    }

    /**
     * {@inheritDoc}
     * @see \exface\Core\Facades\AbstractHttpFacade::createResponseFromError()
     */
    protected function createResponseFromError(\Throwable $exception, ServerRequestInterface $request = null) : ResponseInterface
    {
        $response = parent::createResponseFromError($exception, $request);
        if ($response->getStatusCode() !== 401 && $request !== null && stripos($request->getUri()->getPath(), '/deepchat') !== false) {
            switch (true) {
                // Get the prompt from the exception
                case $exception instanceof AiPromptError:
                    $prompt = $exception->getPrompt();
                    break;
                // Get the prompt from the request (if already processed by the TaskReader middleware
                case $request !== null && null !== $prompt = $request->getAttribute(self::REQUEST_ATTR_TASK, null):
                    break;
                default:
                    $prompt = null;
            }
            // TODO What if we did not save the conversation? Make GenericAssistant::createConversation() public?
            // Create a class AiConversation, that will take care of saving conversations. Extract saveXXX() methods
            // from GenericAssistant and move them to this new class. We could create/laod conversation independently
            // from the assistant classes.
            
            
            // @see https://deepchat.dev/docs/connect#Response
            $conversationID = null;
            

            switch (true) {
                // Get the prompt from the exception
                case $exception instanceof AiPromptError:
                    $prompt = $exception->getPrompt();
                    $conversationID = $prompt->getConversationUid();
                    break;
                // Get the prompt from the request (if already processed by the TaskReader middleware
                case $request !== null && null !== $prompt = $request->getAttribute(self::REQUEST_ATTR_TASK, null):
                    break;
                default:
                    $prompt = null;
            }
            
            if($conversationID === null) {
                
            }
            // TODO What if we did not save the conversation? Make GenericAssistant::createConversation() public?
            // Create a class AiConversation, that will take care of saving conversations. Extract saveXXX() methods
            // from GenericAssistant and move them to this new class. We could create/laod conversation independently
            // from the assistant classes.
            
           
            $json = [
                'error' => $exception->getMessage(),
                'conversation' => $conversationID
            ];
            $body = json_encode($json, JSON_UNESCAPED_UNICODE);
            return $response->withBody(Utils::streamFor($body))->withHeader('content-type','application/json');
        }
        return parent::createResponseFromError($exception, $request);
    }

    /**
     * Normalizes DeepChat requests to a single internal structure.
     *
     * DeepChat sends plain text conversations as JSON with a `messages` array.
     * As soon as files are attached, it switches to `multipart/form-data` and
     * sends files separately while serializing message objects into fields like
     * `message1`, `message2`, etc. This method hides that transport difference
     * from the rest of the facade.
     *
     * @param ServerRequestInterface $request
     * @return array Normalized request data with conversation, message, messages, data, files and raw body.
     */
    private function normalizeDeepChatRequest(ServerRequestInterface $request) : array
    {
        $contentType = $request->getHeaderLine('content-type');
        $parsedBody = $request->getParsedBody();
        $body = is_array($parsedBody) ? $parsedBody : [];

        if (stripos($contentType, 'application/json') !== false && empty($body)) {
            $decoded = json_decode((string) $request->getBody(), true);
            if (is_array($decoded)) {
                $body = $decoded;
            }
        }

        $messages = $this->extractDeepChatMessages($body);
        $messageText = $this->extractDeepChatMessageText($body, $messages);
        $data = $this->decodeDeepChatBodyValue($body['data'] ?? null);

        return [
            'content_type' => $contentType,
            'conversation' => $body['conversation'] ?? null,
            'message' => $messageText,
            'messages' => $messages,
            'data' => $data,
            'files' => $request->getUploadedFiles(),
            'body' => $body
        ];
    }

    /**
     * Extracts DeepChat message objects from JSON and multipart request bodies.
     *
     * JSON requests already contain a `messages` array. Multipart requests store
     * the same message objects as JSON strings in indexed fields named
     * `message1`, `message2`, etc., so they are collected in natural order.
     *
     * @param array $body Parsed request body.
     * @return array DeepChat message objects.
     */
    private function extractDeepChatMessages(array $body) : array
    {
        $messages = $this->decodeDeepChatBodyValue($body['messages'] ?? null);
        if (is_array($messages)) {
            return $messages;
        }

        $keys = array_filter(array_keys($body), function($key) {
            return preg_match('/^message\d+$/', (string) $key);
        });
        natsort($keys);

        $result = [];
        foreach ($keys as $key) {
            $message = $this->decodeDeepChatBodyValue($body[$key]);
            if (is_array($message)) {
                $result[] = $message;
            }
        }

        return $result;
    }

    /**
     * Finds the latest user-facing text in a normalized DeepChat payload.
     *
     * The primary source is the `text` property inside the latest DeepChat
     * message object. The direct body fields are kept as fallbacks for older or
     * manually crafted requests that may still send `message`, `text` or
     * `prompt` directly.
     *
     * @param array $body Parsed request body.
     * @param array $messages DeepChat message objects extracted from the body.
     * @return string|null Recognized message text, or null if none was sent.
     */
    private function extractDeepChatMessageText(array $body, array $messages) : ?string
    {
        foreach (array_reverse($messages) as $message) {
            if (is_array($message) && isset($message['text']) && is_string($message['text'])) {
                return $message['text'];
            }
        }

        foreach (['message', 'text', 'prompt'] as $key) {
            if (isset($body[$key]) && is_string($body[$key])) {
                return $body[$key];
            }
        }

        return null;
    }

    /**
     * Decodes JSON string values while leaving non-JSON values untouched.
     *
     * Multipart form fields can only carry strings, so DeepChat message objects
     * and the widget `data` payload arrive JSON-encoded. Plain strings are
     * returned as-is when they are not valid JSON.
     *
     * @param mixed $value Raw body value.
     * @return mixed Decoded value if JSON was found, otherwise the original value.
     */
    private function decodeDeepChatBodyValue($value)
    {
        if (! is_string($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    /**
     * Applies normalized DeepChat request data to the AI prompt task.
     *
     * The agent code expects prompt parameters such as `messages`,
     * `conversation` and `data`. This method writes those parameters from the
     * normalized request and falls back to `setPrompt()` when only a plain text
     * message could be recognized.
     *
     * @param AiPromptInterface $prompt Prompt task created by the middleware.
     * @param array $normalizedRequest Normalized DeepChat request data.
     * @return void
     */
    private function applyDeepChatRequestToPrompt(AiPromptInterface $prompt, array $normalizedRequest) : void
    {
        if (method_exists($prompt, 'setParameter')) {
            $prompt->setParameter('conversation', $normalizedRequest['conversation']);
            $prompt->setParameter('data', $normalizedRequest['data']);
            if (! empty($normalizedRequest['messages'])) {
                $prompt->setParameter('messages', $normalizedRequest['messages']);
                return;
            }
        }

        if ($normalizedRequest['message'] !== null) {
            $prompt->setPrompt($normalizedRequest['message']);
        }
    }

    /**
     * Converts PSR uploaded files to in-memory files used by AI prompts.
     *
     * Uploaded files may be nested because DeepChat appends multiple attachments
     * under the `files` form field. The conversion therefore walks arrays
     * recursively and preserves filename and media type metadata for the agent.
     *
     * @param array $uploadedFiles PSR uploaded files from the server request.
     * @return InMemoryFile[] Files ready to attach to the AI prompt.
     */
    private function createInMemoryFiles(array $uploadedFiles) : array
    {
        $result = [];
        foreach ($uploadedFiles as $fileOrFiles) {
            if (is_array($fileOrFiles)) {
                $result = array_merge($result, $this->createInMemoryFiles($fileOrFiles));
                continue;
            }

            if ($fileOrFiles instanceof UploadedFileInterface) {
                $result[] = new InMemoryFile(
                    $fileOrFiles->getStream()->getContents(),
                    $fileOrFiles->getClientFilename(),
                    $fileOrFiles->getClientMediaType()
                );
            }
        }

        return $result;
    }

    /**
     * Writes compact debug information for DeepChat request parsing.
     *
     * The log deliberately contains keys and the recognized message text, but
     * not uploaded file contents. This makes it easier to diagnose whether a
     * request arrived as JSON or multipart and which DeepChat fields were seen.
     *
     * @param array $normalizedRequest Normalized DeepChat request data.
     * @return void
     */
    private function logDeepChatRequest(array $normalizedRequest) : void
    {
        $logger = $this->getWorkbench()->getLogger();
        if (! method_exists($logger, 'debug')) {
            return;
        }

        $logger->debug('DeepChat request', [
            'content_type' => $normalizedRequest['content_type'],
            'parsed_body_keys' => array_keys($normalizedRequest['body']),
            'uploaded_file_keys' => $this->getUploadedFileKeys($normalizedRequest['files']),
            'message_text' => $normalizedRequest['message']
        ]);
    }

    /**
     * Returns flattened upload field paths for debug logging.
     *
     * PSR upload arrays can be nested for repeated form fields. The returned
     * paths show where files were found without reading or logging their
     * contents.
     *
     * @param array $uploadedFiles PSR uploaded file tree.
     * @param string $prefix Current recursion path.
     * @return string[] Flattened upload keys such as `files.0`.
     */
    private function getUploadedFileKeys(array $uploadedFiles, string $prefix = '') : array
    {
        $keys = [];
        foreach ($uploadedFiles as $key => $fileOrFiles) {
            $currentKey = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($fileOrFiles)) {
                $keys = array_merge($keys, $this->getUploadedFileKeys($fileOrFiles, $currentKey));
                continue;
            }
            if ($fileOrFiles instanceof UploadedFileInterface) {
                $keys[] = $currentKey;
            }
        }

        return $keys;
    }



    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Facades\AbstractHttpFacade\AbstractHttpFacade::getUrlRouteDefault()
     */
    public function getUrlRouteDefault(): string
    {
        return 'api/aichat';
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Facades\AbstractHttpFacade\AbstractHttpFacade::getMiddleware()
     */
    protected function getMiddleware() : array
    {
        $middleware = parent::getMiddleware();

        // Parse JSON body if it is a JSON and make it available via `$request->getParsedBody()`
        $middleware[] = new JsonBodyParser();
        
        // Generate a task and save it in the request attributes
        $middleware[] = new TaskReader($this, self::REQUEST_ATTR_TASK, function(AiChatFacade $facade, ServerRequestInterface $request){
            return new AiPrompt($facade->getWorkbench(), $facade, $request); 
        }, 
        // URL parameters, that we need in the task
        [
            'object' => 'object_alias',
            'page' => 'page_alias',
            'widget' => 'widget_id'
        ]);
        $middleware[] = new DataUrlParamReader($this, 'data', 'setInputData');
        
        // Add HTTP basic auth for simpler API testing. This allows to log in with
        // username and password from API clients like PostMan.
        // TODO remove authentication after initial testing phase
        $middleware[] = new AuthenticationMiddleware($this, [
            [AuthenticationMiddleware::class, 'extractBasicHttpAuthToken'],
            [AuthenticationMiddleware::class, 'extractBearerTokenAsApiKey']
        ]);
        
        return $middleware;
    }

    protected function findAgent(string $selector)
    {
        // TODO find agent by selector once an agent list is implemented
        $agent = AiFactory::createAgentFromString($this->getWorkbench(), $selector);
        return $agent;
    }
}