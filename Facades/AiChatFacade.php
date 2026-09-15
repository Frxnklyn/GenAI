<?php
namespace axenox\GenAI\Facades;

use axenox\GenAI\Common\AiPrompt;
use axenox\GenAI\Exceptions\AiPromptError;
use axenox\GenAI\Facades\Middleware\FormDataMiddleware;
use axenox\GenAI\Interfaces\AiPromptInterface;
use exface\Core\CommonLogic\Filesystem\InMemoryFile;
use Psr\Http\Message\UploadedFileInterface;
use exface\Core\Exceptions\Facades\FacadeRoutingError;
use exface\Core\Exceptions\UnexpectedValueException;
use exface\Core\Facades\AbstractHttpFacade\Middleware\AuthenticationMiddleware;
use exface\Core\Facades\AbstractHttpFacade\Middleware\DataUrlParamReader;
use exface\Core\Facades\AbstractHttpFacade\Middleware\JsonBodyParser;
use exface\Core\Facades\AbstractHttpFacade\Middleware\TaskReader;
use axenox\GenAI\Factories\AiFactory;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\DataTypes\AiMessageTypeDataType;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\Factories\DataSheetFactory;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
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

        // Files attached in the chat are sent as regular multipart/form-data uploads (see AIChat
        // widget and FormDataMiddleware). Collect them all - DeepChat sends every file under the
        // field name `files`, so getUploadedFiles() may return nested arrays.
        $inMemoryFiles = [];
        foreach ($this->flattenUploadedFiles($request->getUploadedFiles()) as $file) {
            $inMemoryFiles[] = new InMemoryFile($file->getStream()->getContents(), $file->getClientFilename(), $file->getClientMediaType());
        }
        // api/aichat/exface.Core.SqlFilterAgent/completions -> exface.Core.SqlFilterAgent/completions
        $pathInFacade = StringDataType::substringAfter($path, $this->getUrlRouteDefault() . '/');
        // exface.Core.SqlFilterAgent/completions -> exface.Core.SqlFilterAgent, completions
        list($agentSelector, $pathInFacade) = explode('/', $pathInFacade, 2);
        $pathInFacade = mb_strtolower($pathInFacade);
        
        
        try{
            $prompt = $request->getAttribute(self::REQUEST_ATTR_TASK);
            if(!$prompt instanceof AiPromptInterface){
                throw new UnexpectedValueException("Request not delivered a AI Prompt");
            }
            $prompt->setFiles($inMemoryFiles);
            $agent = $this->findAgent($agentSelector);
        // Do the routing here
            switch (true) {     
                case $pathInFacade === 'completions':

                    $response = $agent->handle($prompt);
                    $responseCode = 200;
                    $headers['content-type'] = 'application/json';
                    $body = json_encode($response->toArray(), JSON_UNESCAPED_UNICODE);
                    break;
                // Deepchat format - see https://deepchat.dev/docs/connect#Response
                case $pathInFacade === 'deepchat':

                    $response = $agent->handle($prompt);
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
                // List the conversations of the current user for this agent - used by the AIChat
                // widget to populate its conversation history dropdown.
                case $pathInFacade === 'conversations':

                    $responseCode = 200;
                    $headers['content-type'] = 'application/json';
                    $body = json_encode([
                            'conversations' => $this->getConversationsForCurrentUser($agent)
                        ]
                        , JSON_UNESCAPED_UNICODE
                    );
                    break;
                // Load the messages of a single conversation (belonging to the current user) in
                // DeepChat message format - used by the AIChat widget to load history on selection.
                case $pathInFacade === 'conversations/messages':

                    $conversationId = $request->getQueryParams()['conversation'] ?? null;
                    if ($conversationId === null || $conversationId === '') {
                        throw new UnexpectedValueException('Missing required query parameter "conversation"!');
                    }
                    $responseCode = 200;
                    $headers['content-type'] = 'application/json';
                    $body = json_encode(
                            $this->getConversationMessagesForCurrentUser($agent, $conversationId)
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

        // If files are attached, DeepChat sends multipart/form-data with the messages as separate
        // `messageN` fields. Reconstruct the regular `messages` array from them before the task is
        // created, so the prompt extraction stays the same as for plain JSON requests.
        $middleware[] = new FormDataMiddleware();
        
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

    /**
     * Returns the conversations of the currently authenticated user for the given agent.
     *
     * Scoped to the current user to prevent one user from listing another user's conversations.
     *
     * @param AiAgentInterface $agent
     * @return array
     */
    protected function getConversationsForCurrentUser(AiAgentInterface $agent) : array
    {
        $userUid = $this->getWorkbench()->getSecurity()->getAuthenticatedUser()->getUid();

        $sheet = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.GenAI.AI_CONVERSATION');
        $sheet->getColumns()->addMultiple(['UID', 'TITLE', 'CREATED_ON']);
        $sheet->getFilters()->addConditionFromString('AI_AGENT', $agent->getUid());
        $sheet->getFilters()->addConditionFromString('USER', $userUid);
        $sheet->getSorters()->addFromString('CREATED_ON', 'DESC');
        $sheet->setRowsLimit(50);
        $sheet->dataRead();

        $result = [];
        foreach ($sheet->getRows() as $row) {
            $result[] = [
                'id' => $row['UID'],
                'title' => $row['TITLE'],
                'date' => $row['CREATED_ON']
            ];
        }
        return $result;
    }

    /**
     * Returns the user/assistant messages of the given conversation in DeepChat message format.
     *
     * The conversation is additionally filtered by the current user's UID (via the AI_CONVERSATION
     * relation), so a user cannot load another user's conversation by guessing its UID.
     *
     * @param AiAgentInterface $agent
     * @param string $conversationId
     * @return array
     */
    protected function getConversationMessagesForCurrentUser(AiAgentInterface $agent, string $conversationId) : array
    {
        $userUid = $this->getWorkbench()->getSecurity()->getAuthenticatedUser()->getUid();

        $conversationSheet = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.GenAI.AI_CONVERSATION');
        $conversationSheet->getColumns()->addMultiple(['UID', 'TITLE', 'CREATED_ON']);
        $conversationSheet->getFilters()->addConditionFromString('UID', $conversationId);
        $conversationSheet->getFilters()->addConditionFromString('AI_AGENT', $agent->getUid());
        $conversationSheet->getFilters()->addConditionFromString('USER', $userUid);
        $conversationSheet->dataRead();

        if ($conversationSheet->isEmpty()) {
            throw new UnexpectedValueException('Conversation "' . $conversationId . '" not found!');
        }

        $messageSheet = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.GenAI.AI_MESSAGE');
        $messageSheet->getColumns()->addMultiple(['ROLE', 'MESSAGE', 'SEQUENCE_NUMBER']);
        $messageSheet->getFilters()->addConditionFromString('AI_CONVERSATION', $conversationId);
        $messageSheet->getFilters()->addConditionFromString('ROLE', AiMessageTypeDataType::USER . ',' . AiMessageTypeDataType::ASSISTANT, ComparatorDataType::IN);
        $messageSheet->getSorters()->addFromString('SEQUENCE_NUMBER', 'ASC');
        $messageSheet->dataRead();

        $messages = [];
        foreach ($messageSheet->getRows() as $row) {
            $messages[] = [
                'text' => $row['MESSAGE'],
                'role' => $row['ROLE'] === AiMessageTypeDataType::ASSISTANT ? 'ai' : 'user'
            ];
        }

        return [
            'conversation' => $conversationId,
            'title' => $conversationSheet->getRow(0)['TITLE'],
            'date' => $conversationSheet->getRow(0)['CREATED_ON'],
            'messages' => $messages
        ];
    }

    /**
     * Flattens the (possibly nested) array returned by `$request->getUploadedFiles()` into a flat
     * list of UploadedFileInterface instances.
     * 
     * @param array $uploadedFiles
     * @return UploadedFileInterface[]
     */
    protected function flattenUploadedFiles(array $uploadedFiles) : array
    {
        $result = [];
        foreach ($uploadedFiles as $file) {
            if ($file instanceof UploadedFileInterface) {
                $result[] = $file;
            } elseif (is_array($file)) {
                $result = array_merge($result, $this->flattenUploadedFiles($file));
            }
        }
        return $result;
    }
}