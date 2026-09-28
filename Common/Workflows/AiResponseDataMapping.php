<?php
namespace axenox\GenAI\Common\Workflows;

use axenox\GenAI\Interfaces\AiResponseInterface;
use axenox\GenAI\Interfaces\AiWorkflowExecutionContextInterface;
use exface\Core\CommonLogic\Traits\ICanBeConvertedToUxonTrait;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\InvalidArgumentException;
use exface\Core\Interfaces\iCanBeConvertedToUxon;
use Flow\JSONPath\JSONPath;

/**
 * One explicit response source-to-state target mapping.
 */
class AiResponseDataMapping implements iCanBeConvertedToUxon
{
    use ICanBeConvertedToUxonTrait;

    private string $from = '';

    private string $toAttributeAlias = '';

    private string $toVariable = '';

    /**
     * Creates one mapping from UXON.
     */
    public function __construct(UxonObject $uxon)
    {
        $this->importUxonObject($uxon);
        if ($this->from === '') {
            throw new InvalidArgumentException('AI response mappings require from');
        }
        if (($this->toAttributeAlias === '') === ($this->toVariable === '')) {
            throw new InvalidArgumentException(
                'AI response mappings require exactly one of to_attribute_alias or to_variable'
            );
        }
    }

    /**
     * Extracts and stores this mapping's configured value.
     */
    public function apply(
        AiResponseInterface $response,
        AiWorkflowExecutionContextInterface $context
    ) : void {
        $value = $this->extract($response);
        if ($this->toVariable !== '') {
            $context->setVariable($this->toVariable, $value);
            return;
        }

        $data = $context->getData()->copy();
        $rowIndexes = $data->getRowIndexes();
        $rowIndex = reset($rowIndexes);
        if ($rowIndex === false) {
            throw new InvalidArgumentException(
                'Cannot map an AI response to attribute "' . $this->toAttributeAlias . '" of an empty DataSheet'
            );
        }
        $data->setCellValue($this->toAttributeAlias, $rowIndex, $value);
        $context->setData($data);
    }

    /**
     * Extracts a supported source from the response.
     *
     * @return mixed
     */
    private function extract(AiResponseInterface $response)
    {
        switch ($this->from) {
            case 'message':
                return $response->getMessage();
            case 'conversation_id':
                return $response->getConversationId();
            default:
                if (strpos($this->from, 'json_path:') === 0) {
                    $path = trim(substr($this->from, strlen('json_path:')));
                    if ($path === '') {
                        throw new InvalidArgumentException('AI response json_path must not be empty');
                    }
                    return (new JSONPath($response->getJson()))->find($path)->first();
                }
        }
        throw new InvalidArgumentException('Unsupported AI response mapping source "' . $this->from . '"');
    }

    /**
     * Selects `message`, `conversation_id` or `json_path:<expression>`.
     *
     * @uxon-property from
     * @uxon-type string
     * @uxon-required true
     */
    protected function setFrom(string $source) : AiResponseDataMapping
    {
        $this->from = trim($source);
        return $this;
    }

    /**
     * @uxon-property to_attribute_alias
     * @uxon-type metamodel:attribute
     */
    protected function setToAttributeAlias(string $attributeAlias) : AiResponseDataMapping
    {
        $this->toAttributeAlias = trim($attributeAlias);
        return $this;
    }

    /**
     * @uxon-property to_variable
     * @uxon-type string
     */
    protected function setToVariable(string $variable) : AiResponseDataMapping
    {
        $this->toVariable = trim($variable);
        return $this;
    }
}