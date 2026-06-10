<?php
namespace axenox\GenAI\AI\Tools;

use axenox\GenAI\AI\Traits\FileAccessToolTrait;
use axenox\GenAI\Common\AbstractAiTool;
use axenox\GenAI\Common\AiToolResultMedia;
use axenox\GenAI\Exceptions\AiToolRuntimeError;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiToolResultInterface;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\DataTypes\MimeTypeDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * This AI tool allows an LLM to read an image from selected folders and provide it as multimodal input.
 * 
 * ## Example configuration in an assistant
 * 
 * ```
 *  {
 *      "instructions": "You analyze screenshots and choose a framework",
 *      "tools": {
 *          "read_image": {
 *              "alias": "axenox.GenAI.ReadImageTool",
 *              "description": "Read an image from the test folder",
 *              "use_vendor_folder_as_base": true,
 *              "base_path": "axenox/genai",
 *              "allowed_paths": [
 *                  "test/*.png",
 *                  "test/*.jpg",
 *                  "test/*.jpeg",
 *                  "test/*.webp"
 *              ],
 *              "arguments": [
 *                  {
 *                      "name": "path",
 *                      "description": "Path including filename and extension relative to the configured base path",
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
 */
class ReadImageTool extends AbstractAiTool
{
    use FileAccessToolTrait;

    public const ARG_PATH = 'path';

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiToolInterface::invoke()
     */
    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments): AiToolResultInterface
    {
        $relativePath = (string) ($arguments[0] ?? '');
        $basePath = $this->getBasePathAbsolute();
        $absolutePath = $this->getPathAbsolute($relativePath, $basePath, $prompt);

        if (! is_file($absolutePath)) {
            throw new AiToolRuntimeError($this, $prompt, 'Invalid path: target image does not exist.');
        }

        if (! is_readable($absolutePath)) {
            throw new AiToolRuntimeError($this, $prompt, 'Access denied: target image is not readable.');
        }

        $content = file_get_contents($absolutePath);
        if ($content === false) {
            throw new AiToolRuntimeError($this, $prompt, 'Failed to read image: ' . $relativePath);
        }

        $mimeType = $this->guessMimeType($absolutePath);
        if (! str_starts_with($mimeType, 'image/')) {
            throw new AiToolRuntimeError($this, $prompt, 'Invalid file type: expected an image, got ' . $mimeType . '.');
        }

        $dataUrl = 'data:' . $mimeType . ';base64,' . base64_encode($content);
        $message = 'Image loaded: ' . $relativePath . ' (' . $mimeType . ', ' . strlen($content) . ' bytes). Use the attached image for visual analysis.';

        return new AiToolResultMedia(
            $this,
            $arguments,
            $message,
            $this->getReturnDataType(),
            [[
                'type' => 'input_image',
                'image_url' => $dataUrl,
            ]]
        );
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Common\AbstractAiTool::getArgumentsTemplates()
     */
    protected static function getArgumentsTemplates(WorkbenchInterface $workbench): array
    {
        $self = new self($workbench);
        return [
            (new ServiceParameter($self))
                ->setName(self::ARG_PATH)
                ->setDescription('Path to the image file relative to the configured base path.'),
        ];
    }

    public function getReturnDataType(): DataTypeInterface
    {
        return DataTypeFactory::createFromPrototype($this->getWorkbench(), StringDataType::class);
    }

    protected function guessMimeType(string $absolutePath): string
    {
        $mimeType = function_exists('mime_content_type') ? mime_content_type($absolutePath) : false;
        if (is_string($mimeType) && $mimeType !== '') {
            return $mimeType;
        }

        return MimeTypeDataType::guessMimeTypeOfExtension(
            strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION)),
            'application/octet-stream'
        );
    }
}