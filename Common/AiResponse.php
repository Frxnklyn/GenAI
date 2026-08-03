<?php
namespace axenox\GenAI\Common;
use exface\Core\CommonLogic\Tasks\ResultData;
use axenox\GenAI\Factories\AiResponseStatusMessageFactory;
use axenox\GenAI\Interfaces\AiResponseInterface;
use axenox\GenAI\Interfaces\AiResponseStatusMessageInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;

class AiResponse extends ResultData implements AiResponseInterface
{
    private $message = null;
    private $conversationId = null;
    private $rawJson = null;

    /** @var AiToolCallResponse[] */
    private array $toolCalls = [];
    
    /** @var AiResponseStatusMessageInterface[] */
    private array $statusMessages = [];

    public function __construct(TaskInterface $prompt, string $answer = null, ?string $conversationId = null, array $rawJson = null)
    {
        parent::__construct($prompt);
        $this->message = $answer;
        $this->conversationId = $conversationId;
        $this->rawJson = $rawJson;
    }

    public function toArray() : array
    {
        return $this->rawJson ?? $this->message ?? [];
    }

    /**
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\Tasks\ResultMessage::getMessage()
     */
    public function getMessage() : string 
    {
        return $this->message;
    }
    
    public function getJson(): array
    {
        if(!$this->rawJson){
            return ["message" => $this->message];
        }else {
            return $this->rawJson;
        }
    }
    
    public function getConversationId() : string 
    {
        return $this->conversationId;
    }

    public function getToolCallResponses(): array
    {
        return $this->toolCalls;
    }
    
    public function getStatusMessages(): array
    {
        return $this->statusMessages;
    }
    
    public function addErrorStatusMessage(string $message) : self
    {
        $this->statusMessages[] = AiResponseStatusMessageFactory::createErrorMessage($message);
        return $this;
    }
    
    public function addOKStatusMessage(string $message) : self
    {
        $this->statusMessages[] = AiResponseStatusMessageFactory::createOkMessage($message);
        return $this;
    }

    /**
     * Add a single status message
     * 
     * @param AiResponseStatusMessageInterface $message
     * @return AiResponse
     */
    public function addStatusMessage(AiResponseStatusMessageInterface $message): self
    {
        $this->statusMessages[] = $message;
        return $this;
    }

    /**
     * @param AiToolCallResponse[] $toolCalls
     */
    public function setToolCalls(array $toolCalls): AiResponse
    {
        $this->toolCalls = $toolCalls;
        return $this;
    }
}