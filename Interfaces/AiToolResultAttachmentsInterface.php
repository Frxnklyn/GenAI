<?php
namespace axenox\GenAI\Interfaces;

use exface\Core\Interfaces\Filesystem\FileInfoInterface;

/**
 * Optional capability for tool results that include generated files.
 */
interface AiToolResultAttachmentsInterface extends AiToolResultInterface
{
    /**
     * Returns files produced by this tool call.
     *
     * @return FileInfoInterface[]
     */
    public function getAttachments() : array;

    /**
     * Returns a copy with the provided files associated with this tool call.
     *
     * @param FileInfoInterface[] $attachments
     * @return AiToolResultAttachmentsInterface
     */
    public function withAttachments(array $attachments) : AiToolResultAttachmentsInterface;
}
