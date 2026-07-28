<?php
namespace axenox\GenAI\Interfaces;

use exface\Core\Interfaces\Tasks\ResultDataInterface;
use axenox\GenAI\Common\AiToolCallResponse;
use axenox\GenAI\Interfaces\AiResponseStatusMessageInterface;

/**
 * 
 * @author Andrej Kabachnik
 *
 */
interface AiResponseInterface extends ResultDataInterface
{
    public function toArray() : array;
    
    public function getJson(): array;
    public function getConversationId() : string ;

    //ToolResponse

    /**
     * @return AiToolCallResponse[]
     */
    public function getToolCallResponses(): array;
    
    public function addOKStatusMessage(string $message) : AiResponseInterface;
    
    public function addErrorStatusMessage(string $message) : AiResponseInterface;

    /**
     * Add a single status message.
     */
    public function addStatusMessage(AiResponseStatusMessageInterface $message) : AiResponseInterface;

    /**
     * Add multiple status messages from tools or other sources
     * 
     * @param AiResponseStatusMessageInterface[] $messages
     * @return AiResponseInterface
     */
    public function addStatusMessages(array $messages) : AiResponseInterface;

    /**
     * Get all status messages for display in the chat.
     * 
     * Returns an array of AiResponseStatusMessageInterface objects that provide transparency
     * about what tools and agents did during execution (e.g., "3 records saved").
     * 
     * @return AiResponseStatusMessageInterface[]
     */
    public function getStatusMessages() : array;
}