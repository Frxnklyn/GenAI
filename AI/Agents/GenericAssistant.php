<?php
namespace axenox\GenAI\AI\Agents;

use axenox\GenAI\AI\ResponseStatusMessages\AiResponseStatusMessageWithConfirmation;
use axenox\GenAI\Common\AiResponse;
use axenox\GenAI\Common\AiConversation;
use axenox\GenAI\Common\AiToolCallResponse;
use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Factories\AiResponseStatusMessageFactory;
use axenox\GenAI\Interfaces\AiToolConfirmationResultInterface;
use axenox\GenAI\Common\DataQueries\OpenAiApiDataQuery;
use axenox\GenAI\Exceptions\AiAgentNotFoundError;
use axenox\GenAI\Exceptions\AiAgentRuntimeError;
use axenox\GenAI\Exceptions\AiConceptRenderingError;
use axenox\GenAI\Exceptions\AiConnectionNotFoundError;
use axenox\GenAI\Exceptions\AiPromptError;
use axenox\GenAI\Exceptions\AiToolCriticalError;
use axenox\GenAI\Exceptions\AiToolRuntimeError;
use axenox\GenAI\Interfaces\AiConceptInterface;
use axenox\GenAI\Interfaces\AiConversationInterface;
use axenox\GenAI\Interfaces\AiToolInterface;
use axenox\GenAI\Uxon\AiAgentUxonSchema;
use exface\Core\CommonLogic\Traits\AliasTrait;
use exface\Core\CommonLogic\Traits\ICanBeConvertedToUxonTrait;
use exface\Core\CommonLogic\UxonObject;
use axenox\GenAI\Factories\AiFactory;
use exface\Core\DataTypes\ArrayDataType;
use exface\Core\DataTypes\BooleanDataType;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\JsonDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Factories\DataConnectionFactory;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiResponseInterface;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\AppInterface;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\DataSources\DataConnectionInterface;
use axenox\GenAI\Interfaces\AiQueryInterface;
use axenox\GenAI\Interfaces\Selectors\AiAgentSelectorInterface;
use exface\Core\Interfaces\Selectors\AliasSelectorInterface;
use exface\Core\Templates\BracketHashStringTemplateRenderer;
use exface\Core\Templates\Placeholders\AppPlaceholders;
use exface\Core\Templates\Placeholders\ConfigPlaceholders;
use exface\Core\Templates\Placeholders\DataRowPlaceholders;
use exface\Core\Templates\Placeholders\FormulaPlaceholders;
use exface\Core\Widgets\DebugMessage;

/**
 * Generic chat assistant with configurable system prompt
 * 
 * ## Examples
 * 
 * ```
 * {
 *   "system_prompt": "
 *      You are a helpful assistant, who will answer questions about the structure of the following database. 
 *      Here is the DB schema in DBML: \n\n[#metamodel_dbml#]
 *      Answer using the following locale \"[#=User('LOCALE')#]\"
 *   ",
 *   "system_concepts": {
 *     "metamodel_bmdb": {
 *       "class": "\\exface\\Core\\AI\\Concepts\\MetamodelDbmlConcept",
 *       "object_filters": {
 *         "operator": "AND",
 *         "conditions": [
 *           {"expression": "APP__ALIAS", "comparator": "==", "value": "exface.Core"}
 *         ]
 *       }
 *     }
 *   }
 * }
 * 
 * ```
 * 
 * @author Andrej Kabachnik
 */
class GenericAssistant implements AiAgentInterface
{
    use ICanBeConvertedToUxonTrait;

    use AliasTrait;

    private $workbench = null;

    private $systemPrompt = null;
    
    private $sampleSystemPrompt = null;

    private $systemPromptRendered = null;

    private $conceptConfig = [];

    private $dataConnectionAlias = null;

    private $dataConnection = null;

    private $name = null;

    private $selector = null;

    private $agentDataSheet = null;

    private $versionDataSheet = null;

    private $versionRow = null;

    private $responseJsonSchema = null;

    private $devMode = null;

    private $responseAnswerPath = null;

    private $responseTitlePath = null;

    private ?array $tools = null;
    private ?array $toolsUxon = null;
    private ?AiConversationInterface $conversation = null;

    private $maxNumberOfCalls = 10;

    /** @var AiToolCallResponse[] */
    private array $toolCalls = [];

    /** @var AiResponseStatusMessageInterface[] */
    private array $toolStatusMessages = [];

    /** True when handleToolCalls() was interrupted by a confirmation request. */
    private bool $pendingConfirmation = false;

    private $promptSuggestions = [];

    /**
     * 
     * @param \axenox\GenAI\Interfaces\Selectors\AiAgentSelectorInterface $selector
     * @param \exface\Core\CommonLogic\UxonObject|null $uxon
     */
    public function __construct(AiAgentSelectorInterface $selector, UxonObject $uxon = null)
    {
        $this->workbench = $selector->getWorkbench();
        $this->selector = $selector;
        if ($uxon !== null) {
            $this->importUxonObject($uxon);
        }
    }


    public function handle(AiPromptInterface $prompt) : AiResponseInterface
    {
        // Reset tool-related state for this invocation
        $this->toolStatusMessages = [];
        $this->pendingConfirmation = false;

        $userMsg = trim($prompt->getUserPrompt());
        $isConfirmationToken = $userMsg === AiResponseStatusMessageWithConfirmation::CONFIRM_TOKEN
            || $userMsg === AiResponseStatusMessageWithConfirmation::CANCEL_TOKEN;

        // Initialize the data query
        $query = new OpenAiApiDataQuery($this->workbench);
        if (null !== $conversationId = $prompt->getConversationUid()) {
            $query->setConversationUid($conversationId);
        }

        // For normal requests, add the user prompt BEFORE getConversation so that
        // new conversations can use it as the conversation title.
        if (! $isConfirmationToken) {
            $query->appendMessage($prompt->getUserPrompt());
            $query->setFiles($prompt->getFiles());
        }

        // Initialize the conversation
        $conversation = $this->getConversation($prompt, $query);
        if ($conversationId === null) {
            $conversationId = $conversation->getConversationId();
            $prompt->setConversationUid($conversationId);
        }

        // For confirmation tokens, check whether there is actually a pending action.
        // If none is found (e.g. stale click) fall through to the normal path.
        $pending = $isConfirmationToken ? $conversation->loadPendingConfirmation() : null;
        $isResumingToolCall = $pending !== null;

        // Render system prompt (same for both paths)
        try {
            $systemPrompt = $this->getSystemPrompt($prompt);
            $query->setSystemPrompt($systemPrompt);
        } catch (\Throwable $e) {
            $e = new AiPromptError($this, $prompt, 'Failed to render AI prompt. ' . $e->getMessage(), null, $e);
            throw $conversation->saveError($e, $this->getTools(), $this->getResponseJsonSchema());
        }

        // Add JSON schema (same for both paths)
        if ($this->hasResponseJsonSchema()) {
            $query->setResponseJsonSchema($this->getResponseJsonSchema());
            if ($val = $this->getResponseAnswerPath()) {
                $query->setResponseAnswerPath($val);
            }
        }

        // Add tools (same for both paths)
        foreach ($this->getTools() as $tool) {
            $query->addTool($tool);
        }

        if ($isResumingToolCall) {
            // ── CONFIRMATION PATH ────────────────────────────────────────────────
            // Accept:  re-invoke the tool with __confirmed = true, pass its result.
            // Decline: pass "user declined" text – the LLM decides how to respond.
            // Both paths are symmetric from here on.
            $confirmed = $userMsg === AiResponseStatusMessageWithConfirmation::CONFIRM_TOKEN;

            [$toolResultText, $confirmMsgs] = $this->resolveConfirmationResult($prompt, $pending, $confirmed);
            foreach ($confirmMsgs as $msg) {
                $this->toolStatusMessages[] = $msg;
            }

            // Execute remaining tool calls from the original batch (those that came
            // after the confirmation tool and were stored in the pending record).
            $remainingResults = [];
            foreach ($pending['remainingToolCalls'] ?? [] as $remaining) {
                try {
                    $remainingTool   = $this->getTool($remaining['toolName']);
                    $remainingResult = $remainingTool->invoke($this, $prompt, $remaining['args']);
                    $remainingText   = $remainingResult->getValue();
                    foreach ($remainingResult->getStatusMessages() as $msg) {
                        $this->toolStatusMessages[] = $msg;
                    }
                } catch (\Throwable $e) {
                    $remainingText = 'ERROR: ' . $e->getMessage();
                    $this->toolStatusMessages[] = AiResponseStatusMessageFactory::createErrorMessage($e->getMessage());
                }
                $remainingResults[] = [
                    'toolName' => $remaining['toolName'],
                    'callId'   => $remaining['callId'],
                    'result'   => $remainingText,
                ];
            }

            // Save ALL tool results (prior + confirmed/declined + remaining) to the
            // conversation log so the full batch is visible in the admin view.
            $allToolResults = [];
            foreach ($pending['priorToolMessages'] ?? [] as $prior) {
                $allToolResults[] = [
                    'toolName' => $prior['toolName'] ?? 'unknown',
                    'callId'   => $prior['tool_call_id'],
                    'result'   => $prior['content'],
                ];
            }
            $allToolResults[] = [
                'toolName' => $pending['toolName'],
                'callId'   => $pending['callId'],
                'result'   => $toolResultText,
            ];
            foreach ($remainingResults as $rem) {
                $allToolResults[] = $rem;
            }
            $conversation->saveConfirmationToolResults($allToolResults);

            // Reconstruct the original tool-call sequence so the LLM receives the
            // complete context: [history] + [assistant tool-call] + [all results].
            $requestMessage = $conversation->loadLastToolCallRequestMessage();
            if ($requestMessage !== null) {
                $query->appendAssistantMessage($requestMessage);
                foreach ($pending['priorToolMessages'] ?? [] as $prior) {
                    $query->appendSingleToolResult($prior['content'], $prior['tool_call_id']);
                }
                $query->appendSingleToolResult($toolResultText, $pending['callId']);
                foreach ($remainingResults as $rem) {
                    $query->appendSingleToolResult($rem['result'], $rem['callId']);
                }
            }

            // Query the LLM BEFORE saving the confirmation answer so that the
            // "confirmed / cancelled" USER message written by saveConfirmationAnswer
            // is not yet in the DB when getConversationData() lazily loads the
            // conversation history inside the connector.
            try {
                $performedQuery = $this->getConnection()->query($query);
            } catch (\Throwable $e) {
                $conversation->saveConfirmationAnswer($confirmed); // always mark answered
                $e = new AiPromptError($this, $prompt, 'Failed to query LLM. ' . $e->getMessage(), null, $e);
                throw $conversation->saveError($e, $this->getTools(), $this->getResponseJsonSchema());
            }
            $conversation->saveConfirmationAnswer($confirmed);

        } else {
            // ── NORMAL PATH ──────────────────────────────────────────────────────
            // If a confirmation token arrived but the pending record is gone (stale
            // button click), treat the message as a regular user input.
            if ($isConfirmationToken) {
                $query->appendMessage($userMsg);
                $query->setFiles($prompt->getFiles());
            }

            try {
                $conversation->saveSystemPrompt($query, $systemPrompt, $this->getTools(), $this->getResponseJsonSchema());
                $conversation->saveUserPrompt($query);
            } catch (\Throwable $e) {
                $e = new AiPromptError($this, $prompt, 'Failed to save AI conversation. ' . $e->getMessage(), null, $e);
                throw $conversation->saveError($e, $this->getTools(), $this->getResponseJsonSchema());
            }

            try {
                $performedQuery = $this->getConnection()->query($query);
            } catch (\Throwable $e) {
                $e = new AiPromptError($this, $prompt, 'Failed to query LLM. ' . $e->getMessage(), null, $e);
                throw $conversation->saveError($e, $this->getTools(), $this->getResponseJsonSchema());
            }
        }

        // ── SHARED EXECUTION PATH (Accept, Decline, and normal all end up here) ─
        try {
            $performedQuery = $this->handleToolCalls($prompt, $performedQuery, $conversation);
        } catch (\Throwable $e) {
            if (! $e instanceof AiToolRuntimeError) {
                $e = new AiPromptError($this, $prompt, 'Failed to call AI tools. ' . $e->getMessage(), null, $e);
            }
            throw $conversation->saveError($e, $this->getTools(), $this->getResponseJsonSchema());
        }

        // If a tool requested confirmation, return the dialog and wait.
        if ($this->pendingConfirmation) {
            $response = new AiResponse($prompt, '', $conversation->getConversationId());
            foreach ($this->toolStatusMessages as $statusMessage) {
                $response->addStatusMessage($statusMessage);
            }
            return $response;
        }

        try {
            $conversation->saveResponse(
                $performedQuery,
                $performedQuery->getAnswerMarkdown($performedQuery),
                $this->hasResponseJsonSchema() ? $performedQuery->getAnswerJson() : null
            );
            return $this->parseDataQueryResponse($prompt, $performedQuery, $conversation->getConversationId());
        } catch (\Throwable $e) {
            $e = new AiPromptError($this, $prompt, 'Failed to process AI response. ' . $e->getMessage(), null, $e);
            throw $conversation->saveError($e, $this->getTools(), $this->getResponseJsonSchema());
        }
    }

    /**
     * Resolves the tool result for a confirmation response.
     *
     * Accept: re-invokes the tool with `__confirmed = true`.
     * Decline: returns a fixed "user declined" text — the LLM decides the reply.
     *
     * Returns [$toolResultText, $statusMessages[]].
     *
     * @param AiPromptInterface $prompt
     * @param array             $pending  Pending confirmation data from the DB.
     * @param bool              $confirmed TRUE when the user clicked "Yes".
     * @return array{0: string, 1: AiResponseStatusMessageInterface[]}
     */
    protected function resolveConfirmationResult(
        AiPromptInterface $prompt,
        array $pending,
        bool $confirmed
    ): array {
        if ($confirmed) {
            try {
                $tool = $this->getTool($pending['toolName']);
                $args = $pending['args'];
                $args['__confirmed'] = true;
                $result = $tool->invoke($this, $prompt, $args);
                return [$result->getValue(), $result->getStatusMessages()];
            } catch (\Throwable $e) {
                return [
                    'ERROR: ' . $e->getMessage(),
                    [AiResponseStatusMessageFactory::createErrorMessage($e->getMessage())],
                ];
            }
        }

        return [
            'The user declined to execute the action.',
            [AiResponseStatusMessageFactory::createInfoMessage('Action cancelled.')],
        ];
    }

    /**
     * Returns the current conversation helper for the prompt.
     *
     * Reuses the existing helper if it matches the prompt conversation ID,
     * otherwise creates a new helper and initializes the prompt conversation.
     */
    protected function getConversation(AiPromptInterface $prompt, ?AiQueryInterface $query = null) : AiConversationInterface
    {
        $promptConversationId = $prompt->getConversationUid();

        if ($this->conversation === null) {
            $this->conversation = new AiConversation($this, $prompt, $promptConversationId, $query);
            return $this->conversation;
        }

        if ($promptConversationId === null || $this->conversation->getConversationId() !== $promptConversationId) {
            $this->conversation = new AiConversation($this, $prompt, $promptConversationId, $query);
        }

        return $this->conversation;
    }
    
    protected function handleToolCalls(AiPromptInterface $prompt, AiQueryInterface $performedQuery, AiConversationInterface $conversation) : AiQueryInterface
    {
        $numberOfCallResponses = 0;
        // Check if the LLM has put some tool calls in its response
        while ($performedQuery->hasToolCalls()) {
            $numberOfCallResponses++;
            $conversation->saveToolCallRequest($performedQuery);

            if ($numberOfCallResponses > $this->maxNumberOfCalls) {
                // Add an AiQueryError that will accept the $performedQuery too, so that we see the actual
                // HTTP messages in the logs
                throw new AiPromptError($this, $prompt, 'Too many recursive tool call responses from LLM: ' . $numberOfCallResponses . ' one after another!');
            }

            $requestedCalls = $performedQuery->getToolCalls();
            $existingCall = false;
            $pendingConfirmationDetected = false;

            foreach($requestedCalls as $call){
                $resultOfTool = null;
                $tool = $this->getTool($call->getToolName());
                $args = array_values($call->getArguments());
                if ($this->maxNumberOfCalls >= $numberOfCallResponses) {
                    $resultOfTool = null;
                    try {
                        $resultOfTool = $tool->invoke($this, $prompt, $args);

                        // Confirmation requested – save the pending state and break out
                        // of both the foreach and the while loop without querying the LLM.
                        if ($resultOfTool instanceof AiToolConfirmationResultInterface) {
                            // Collect results of tools that already ran in this batch (before the confirmation one)
                            $priorToolMessages = [];
                            foreach ($toolCallResponses ?? [] as $priorCallId => $tcr) {
                                $priorToolMessages[] = [
                                    'tool_call_id' => $priorCallId,
                                    'toolName'     => $tcr->getToolName(),
                                    'content'      => $tcr->getToolResult()->getValue(),
                                ];
                            }

                            // Collect tool calls that come AFTER the confirmation one (not yet executed)
                            $remainingToolCalls = [];
                            $foundConfirmation  = false;
                            foreach ($requestedCalls as $otherCall) {
                                if ($otherCall->getCallId() === $call->getCallId()) {
                                    $foundConfirmation = true;
                                    continue;
                                }
                                if ($foundConfirmation) {
                                    $remainingToolCalls[] = [
                                        'toolName' => $otherCall->getToolName(),
                                        'callId'   => $otherCall->getCallId(),
                                        'args'     => $otherCall->getArguments(),
                                    ];
                                }
                            }

                            $conversation->savePendingConfirmation(
                                $call->getToolName(),
                                $call->getCallId(),
                                $call->getArguments(), // named args for the re-invoke
                                $resultOfTool->getConfirmationQuestion(),
                                $priorToolMessages,
                                $remainingToolCalls
                            );
                            $this->toolStatusMessages[] = new AiResponseStatusMessageWithConfirmation(
                                $resultOfTool->getConfirmationQuestion()
                            );
                            $this->pendingConfirmation   = true;
                            $pendingConfirmationDetected = true;
                            break;
                        }

                        $exceptions = $resultOfTool->getExceptions();
                        // Collect status messages from the tool result
                        foreach ($resultOfTool->getStatusMessages() as $statusMsg) {
                            $this->toolStatusMessages[] = $statusMsg;
                        }
                    } catch (\Throwable $e) {
                        if (! $e instanceof AiToolCriticalError) {
                            $e = new AiToolCriticalError($tool, $prompt, 'Unexpected error in AI tool. ' . $e->getMessage(), null, $e);
                        }
                        $resultOfTool = new AiToolResultString($tool, $args, 'ERROR: Tool execution failed. ' . $e->getMessage());
                        $exceptions = [$e];
                    }
                    foreach ($exceptions as $e) {
                        $this->getWorkbench()->getLogger()->logException($e);
                    }
                    $conversation->saveExceptions($exceptions);
                    
                    // On critical errors, we should tell the LLM not to use this tool anymore. It will either tell the
                    // user or continue with other tools.
                    if ($resultOfTool && $resultOfTool->isFailed()) {
                        // TODO should we give more error details to the LLM
                        $resultOfTool = new AiToolResultString($tool, $args, "ERROR: Tool execution failed. It seems, this tool is broken.");
                    }
                    
                } else {
                    $resultOfTool = new AiToolResultString($tool, $args, "ERROR: Maximum number of tool calls ({$numberOfCallResponses}) have been reached.");
                    // TODO is this actually an error? Should we log an exception here?
                } 

                //to prevent duplication on calls
                $callId = $call->getCallId();

                $toolCallResponses[$callId] = new AiToolCallResponse(
                    $call->getToolName(),
                    $callId,
                    $call->getArguments(),
                    $resultOfTool
                );

                $this->toolCalls[] = $toolCallResponses[$callId];

                $performedQuery->appendToolMessages($existingCall, $resultOfTool, $callId, $performedQuery->getResponseMessage());
                $existingCall = true;
            }
            if ($pendingConfirmationDetected) {
                break; // Exit the while loop – no further LLM call for this turn
            }
            $toolCallResponses = $conversation->saveToolResponses($performedQuery, $toolCallResponses);
            // $toolCallResponses = null;
            $performedQuery = $this->getConnection()->query($performedQuery);
            //$query->clearPreviousToolCalls();
        }
        return $performedQuery;
    }

    /**
     * AI concepts to be used in the system prompt
     * 
     * Each concept is basically a plugin, that generates part of the system prompt. You can use it anywhere in your
     * prompt via placeholder
     * 
     * @uxon-property concepts
     * @uxon-type \axenox\GenAI\Common\AbstractConcept
     * @uxon-template {"placeholder_name": {"alias": ""}}
     * 
     * @param \exface\Core\CommonLogic\UxonObject $arrayOfConcepts
     * @return \axenox\GenAI\Interfaces\AiAgentInterface
     */
    protected function setConcepts(UxonObject $arrayOfConcepts) : AiAgentInterface
    {
        $this->conceptConfig = null;
        $this->conceptConfig = $arrayOfConcepts;
        return $this;
    }

    public function getRawConcepts() : ?UxonObject
    {
        if ($this->conceptConfig instanceof UxonObject) {
            return $this->conceptConfig;
        }
        return null;
    }


    /**
     * 
     * @return \axenox\GenAI\Interfaces\AiConceptInterface[]
     */
    protected function getConcepts(AiPromptInterface $prompt, BracketHashStringTemplateRenderer $configRenderer) : array
    {
        $concepts = [];
        foreach ($this->conceptConfig as $placeholder => $uxon) {            
            if(! $uxon->hasProperty('output')) {
                $json = $configRenderer->render($uxon->toJson());
            }
            
            $concepts[] = AiFactory::createConceptFromUxon($this, $prompt, $placeholder, UxonObject::fromJson($json));
        }
        return $concepts;
    }

    /**
     * An introduction to explain the LLM, what the assistant is supposed to do.
     * 
     * ## Available placeholders
     * 
     * - `[#~app:#]` - get properties of the app, where the assistant is called from: e.g. `[#~app:alias#]`
     * - `[#~input:#]` - access the first row of the input data (e.g. data sent by the AIChat widget)
     * - `[#~config:#]`
     * 
     * @uxon-property instructions
     * @uxon-type string
     * @uxon-template You are a helpful assistant, who will answer questions about the structure of the following database. Here is the DB schema in DBML: \n\n[#metamodel_dbml#] \n\nAnswer using the following locale [#=User('LOCALE')#]
     * 
     * @param string $text
     * @return \axenox\GenAI\Interfaces\AiAgentInterface
     */
    protected function setInstructions(string $text) : AiAgentInterface
    {
        $this->systemPrompt = $text;
        return $this;
    }
    
    protected function setSampleSystemPrompt(string $text) : AiAgentInterface
    {
        $this->sampleSystemPrompt = $text;
        return $this;
    }

    /**
     * 
     * @param \axenox\GenAI\Interfaces\AiPromptInterface $promt
     * @return string
     */
    protected function getSystemPrompt(AiPromptInterface $prompt) : string
    {
        if ($this->systemPromptRendered === null) {
            $renderer = new BracketHashStringTemplateRenderer($this->workbench);
            $renderer->addPlaceholder(new FormulaPlaceholders($this->workbench, null, null, '='));
            $renderer->addPlaceholder(new ConfigPlaceholders($this->workbench, '~config:'));
            if (null !== $app = $this->getApp($prompt)) {
                $renderer->addPlaceholder(new AppPlaceholders($app, '~app:'));
            }
            if ($prompt->hasInputData()) {
                $renderer->addPlaceholder(new DataRowPlaceholders($prompt->getInputData(), 0, '~input:'));
            }
            foreach ($this->getConcepts($prompt, $renderer) as $placeholderResolver) {
                $renderer->addPlaceholder($placeholderResolver);
                if ($placeholderResolver instanceof AiConceptInterface) {
                    foreach ($placeholderResolver->getToolModels() as $toolName => $toolUxon) {
                        $this->toolsUxon[$toolName] = $toolUxon;
                    }
                }
            }
            
            try {
                
                if($this->sampleSystemPrompt){
                    $systemPrompt = $this->sampleSystemPrompt;
                } else {
                    $systemPrompt = $this->systemPrompt;
                }
                $this->systemPromptRendered = $renderer->render($systemPrompt ?? '');
            } catch (\Throwable $e) {
                throw new AiConceptRenderingError($renderer, 'Cannot apply AI concepts. ' . $e->getMessage(), null, $e, $systemPrompt);
            }
        }
        return $this->systemPromptRendered;
    }

    protected function getApp(AiPromptInterface $prompt) : ?AppInterface
    {
        $app = null;
        if ($prompt->isTriggeredOnPage() && $prompt->getPageTriggeredOn()->hasApp()) {
            $app = $prompt->getPageTriggeredOn()->getApp();
        }
        // TODO determine the app from input data?
        return $app;
    }
    
    /**
     *
     * {@inheritdoc}
     * @see \exface\Core\Interfaces\iCanBeConvertedToUxon::getUxonSchemaClass()
     */
    public static function getUxonSchemaClass() : ?string
    {
        return AiAgentUxonSchema::class;
    }

    /**
     * 
     * @return \exface\Core\Interfaces\DataSources\DataConnectionInterface
     */
    public function getConnection() : DataConnectionInterface
    {
        if ($this->dataConnection === null) {
            if($this->dataConnectionAlias === null) {
                throw new AiConnectionNotFoundError($this,"No Connection for agent " . $this->getName() . " found!");
            }
            $this->dataConnection = DataConnectionFactory::createFromModel($this->workbench, $this->dataConnectionAlias);
        }
        return $this->dataConnection;
    }
    
    /**
     * 
     * @param string $selector
     * @return \axenox\GenAI\Interfaces\AiAgentInterface
     */
    protected function setDataConnectionAlias(string $selector) : AiAgentInterface
    {
        $this->dataConnectionAlias = $selector;
        return $this;
    }

    /**
     * 
     * @param \axenox\GenAI\Interfaces\AiPromptInterface $prompt
     * @param \axenox\GenAI\Common\DataQueries\OpenAiApiDataQuery $query
     * @return \axenox\GenAI\Common\AiResponse
     */
    protected function parseDataQueryResponse(AiPromptInterface $prompt, OpenAiApiDataQuery $query, string $conversationId) : AiResponse
    {
        if($this->hasResponseJsonSchema()){
            $response = new AiResponse($prompt, $query->getAnswerMarkdown(), $conversationId, $query->getAnswerJson());
        } else {
            $response = new AiResponse($prompt, $query->getAnswerMarkdown(), $conversationId);
        }
        $response->setToolCalls($this->toolCalls);
        // Add status messages collected from tool calls
        foreach ($this->toolStatusMessages as $statusMessage) {
            $response->addStatusMessage($statusMessage);
        }
        
        return $response;
    }

    /**
     * 
     * @param \axenox\GenAI\Interfaces\AiQueryInterface $query
     * @return string
     */
    public function getTitle(AiQueryInterface $query) : string
    {
        if ($this->hasResponseJsonSchema() && $query->hasResponse() && $this->getResponseAnswerPath() !== null) {
            $json = $query->getAnswerJson();
            $title = ArrayDataType::filterJsonPath($json, $this->getResponseTitlePath())[0];
        } else {
            $title = StringDataType::truncate($query->getUserPrompt(), 50, true, true, true);
        }
        return $title;
    }

    /**
     * 
     * @param string $message
     * @param \axenox\GenAI\Interfaces\AiPromptInterface $prompt
     * @param mixed $e
     * @return AiResponse
     */
    protected function createResponseUnavailable(string $message, AiPromptInterface $prompt, ?\Throwable $e = null)
    {
        return new AiResponse($prompt, $message);
    }

    /**
     * 
     * @param string $alias
     * @return \axenox\GenAI\Interfaces\AiAgentInterface
     */
    protected function setAlias(string $alias) : AiAgentInterface
    {
        $this->alias = $alias;
        return $this;
    }

    /**
     * 
     * @return \exface\Core\Interfaces\Selectors\AliasSelectorInterface
     */
    public function getSelector() : AliasSelectorInterface
    {
        return $this->selector;
    }

    /**
     * 
     * @param string $name
     * @return \axenox\GenAI\Interfaces\AiAgentInterface
     */
    protected function setName(string $name) : AiAgentInterface
    {
        $this->name = $name;
        return $this;
    }

    protected function getModelData() : DataSheetInterface
    {
        if ($this->agentDataSheet === null) {
            $sheet = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_AGENT');
            $sheet->getColumns()->addFromSystemAttributes();
            $sheet->getColumns()->addMultiple([
                'NAME'
            ]);
            $sheet->getFilters()->addConditionFromString('ALIAS_WITH_NS', $this->getAliasWithNamespace(), ComparatorDataType::EQUALS);
            $sheet->dataRead();
            switch ($sheet->countRows()) {
                case 0: throw new AiAgentNotFoundError('AI agent "' . $this->getSelector()->__toString() . '" not found!');
                case 1: break;
                default: throw new AiAgentNotFoundError('Multiple AI agents found for "' . $this->getSelector()->__toString() . '"!');
            }
            $this->agentDataSheet = $sheet;
        }
        return $this->agentDataSheet;
    }

    protected function getVersionModelData() : DataSheetInterface
    {
        if($this-> versionDataSheet === null){
            $sheet = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_AGENT_VERSION');
            $sheet->getColumns()->addMultiple([
                    'VERSION',
                    'ENABLED_FLAG',
                    'DATA_CONNECTION'
                ]);
            $sheet->dataRead();
            $this->versionDataSheet = $sheet;
        }
        return $this->versionDataSheet;
        
    }

    protected function getVersionRow()  {
        if($this->versionRow === null){
            $this->versionRow = $this->getVersionModelData()->getRow($this->getVersionModelData()->getColumn('VERSION')->findRowByValue($this->getVersion()));
        }
        return $this->versionRow;
    }

    /**
     * 
     * @return string
     */
    public function getUid() : string
    {
        return $this->getModelData()->getCellValue('UID', 0);
    }

    /**
     * 
     * @return string
     */
    public function getName() : string
    {
        return $this->getModelData()->getCellValue('NAME', 0);
    }

    /**
     *
     * @return string
     */
    public function getVersion() : string
    {
        return $this->getSelector()->getVersion();
    }

    /**
     * 
     * @return array|null
     */
    protected function getResponseJsonSchema() : ?array
    {
        return $this->responseJsonSchema;
    }

    /**
     * 
     * @return bool
     */
    protected function hasResponseJsonSchema() : bool
    {        
        return $this->responseJsonSchema !== null;
    }

    public function setDevmode(bool $trueOrFalse): AiAgentInterface
    {
        $this->devMode = $trueOrFalse;
        return $this;
    }

    /**
     * 
     * @return bool
     */
    public function getDevmode() : bool
    {
        if($this->devMode === null){
            $this->setDevmode(BooleanDataType::cast($this->getVersionRow()['ENABLED_FLAG']));
        }
        return $this->devMode;
        
    }

    /**
     * If the LLM should respond with a JSON, define its JSONschema here
     * 
     * @uxon-property response_json_schema 
     * @uxon-type object
     * @uxon-template {"type":"object","properties":{"title":{"type":"string","description":"Summary of the conversation"},"text":{"type":"string","description":"Your answer as markdown"}},"additionalProperties":false,"required":["title"]}
     * 
     * @param \exface\Core\CommonLogic\UxonObject $uxon
     * @return static
     */
    public function setResponseJsonSchema(UxonObject $uxon) : AiAgentInterface
    {
        $this->responseJsonSchema = $uxon->toArray();
        return $this;
    }

    /**
     * If the AI should respond with JSON, specify the path where to find its answer/comment in that JSON
     * 
     * @uxon-property response_answer_path
     * @uxon-type string
     * @uxon-template $.text
     * 
     * @param string $jsonPath
     * @return \axenox\GenAI\AI\Agents\GenericAssistant
     */
    protected function setResponseAnswerPath(string $jsonPath) : AiAgentInterface
    {
        $this->responseAnswerPath = $jsonPath;
        return $this;
    } 

    /**
     * Returns the JSONpath to find the text answer in the response JSON if a response_json_schema was provided
     * @return string
     */
    protected function getResponseAnswerPath() : ?string
    {
        return $this->responseAnswerPath;
    }

    /**
     * The JSONPath to the conversation title in the response JSON if a JSON schema is used by this assistant
     * 
     * @uxon-property response_title_path
     * @uxon-type string
     * @uxon-template $.title
     * 
     * @param string $jsonPath
     * @return \axenox\GenAI\AI\Agents\GenericAssistant
     */
    protected function setResponseTitlePath(string $jsonPath) : GenericAssistant
    {
        $this->responseTitlePath = $jsonPath;
        return $this;
    } 

    /**
     * Returns the JSONPath to the conversation title in the response JSON if a JSON schema is used by this assistant
     * 
     * @return string
     */
    protected function getResponseTitlePath() : ?string
    {
        return $this->responseTitlePath;
    }

    /**
     * Tools (function calls) made available to the LLM
     * 
     * ```
     *   {
     *      "tools": {
     *          "GetDocs": {
     *              "description": "Load markdown from our documentation by URL",
     *              "arguments": [
     *                  {
     *                      "name": "uri",
     *                      "description": "Markdown file URL - absolute (with https://...) or relative to api/docs on this server",
     *                      "data_type": {
     *                          "alias": "exface.Core.String"
     *                      }
     *                  }
     *              ]
     *          }
     *      }
     *  }
     *  
     * ```
     * @uxon-property tools
     * @uxon-type \axenox\GenAI\Common\AbstractAiTool[]
     * @uxon-template {"": {"alias": "", "description": ""}}
     * 
     * @param \exface\Core\CommonLogic\UxonObject $objectWithToolDefs
     * @return GenericAssistant
     */
    protected function setTools(UxonObject $objectWithToolDefs) : AiAgentInterface
    {
        foreach ($objectWithToolDefs as $toolName => $toolUxon) {
            $this->toolsUxon[$toolName] = $toolUxon;
        }
        return $this;
    }

    /**
     * 
     * @return AiToolInterface[]
     */
    public function getTools() : array
    {
        if ($this->tools === null) {
            if ($this->toolsUxon === null) {
                $this->tools = [];
            } else {
                foreach ($this->toolsUxon as $toolName => $uxon) {
                    $tool = AiFactory::createToolFromUxon($this->workbench, $uxon, $toolName);
                    $this->addTool($tool);
                }
            }
        }
        return $this->tools;
    }

    /**
     * @param string $name
     * @return AiToolInterface
     */
    public function getTool(string $name) : AiToolInterface
    {
        foreach ($this->getTools() as $tool) {
            if ($tool->getName() === $name) {
                return $tool;
            }
        }
        throw new AiAgentRuntimeError($this, 'Tool "' . $name . '" not found!');
    }

    protected function addTool(AiToolInterface $tool) : AiAgentInterface
    {
        $this->tools[$tool->getName()] = $tool;
        return $this;
    }

    /**
     * defines examples of suggestions for the Prompt
     * 
     * @uxon-property prompt_suggestions
     * @uxon-type UxonObject
     * @uxon-required true
     * @uxon-template [""]
     * 
     * @param UxonObject $alias
     * @return AIChat
     */
    protected function setPromptSuggestions(UxonObject $suggestions) : GenericAssistant
    {
        $array = $suggestions->getPropertiesAll();
        foreach ($array as $s) {
            if (!is_string($s)) {
                
                return $this;
            }
        }

        $this->promptSuggestions = $array;
        return $this;
    }

    public function getPromptSuggestions(): array
    {
        return $this->promptSuggestions;
    }
    
    public function getWorkbench()
    {
        return $this->workbench;
    }

    /**
     * @param DebugMessage $debugWidget
     * @return void
     */
    public function createDebugWidget(DebugMessage $debugWidget)
    {
        foreach ($debugWidget->getTabs() as $tab) {
            if ($tab->getCaption() === 'AI Agent') {
                return $debugWidget;
            }
        }
        $tab = $debugWidget->createTab();
        $tab->setCaption('AI Agent');
        $tab->setWidgets(new UxonObject([[
            'widget_type' => 'InputUxon',
            'disabled' => true,
            'width' => '100%',
            'height' => '100%',
            'hide_caption' => true,
            'value' => $this->exportUxonObject()->toJson(),
        ]]));
        $debugWidget->addTab($tab);
        return $debugWidget;
    }

    /**
     * Maximum number of tool calls before a response
     * 
     * @uxon-property tool_calls_max
     * @uxon-type integer
     * @uxon-default 30
     * 
     * @param int $number
     * @return $this
     */
    protected function setToolCallsMax(int $number) : GenericAssistant
    {
        $this->maxNumberOfCalls = $number;
        return $this;
    }
}