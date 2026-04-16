<?php
namespace axenox\GenAI\AI\Concepts;

use axenox\GenAI\Common\AbstractConcept;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Factories\UiPageFactory;
use exface\Core\Factories\WidgetFactory;
use exface\Core\Widgets\DataTable;


/**
 * 
 * 
 */
class DataTableConcept extends AbstractConcept
{
    private ?DataTable $dataTable = null;

    protected function getOutput(): string
    {
        if ($this->dataTable === null) {
            return '';
        }

        $columns = $this->dataTable->getColumns();
        if (empty($columns)) {
            return '';
        }

        $dataSheet = $this->dataTable->prepareDataSheetToRead();
        $dataSheet->dataRead();

        $headers = [];
        $columnKeys = [];

        foreach ($columns as $column) {
            $originalUxon = $column->exportUxonObjectOriginal();
            $caption = trim((string) ($originalUxon->getProperty('caption') ?? ''));
            $attributeAlias = trim((string) ($column->getAttributeAlias() ?? ''));
            $columnKey = $column->getDataColumnName();

            if ($caption === '') {
                $caption = $attributeAlias !== '' ? $attributeAlias : $columnKey;
            }

            $headers[] = $this->escapeMarkdownCell($caption);
            $columnKeys[] = $columnKey;
        }

        $lines = [];
        $lines[] = '| ' . implode(' | ', $headers) . ' |';
        $lines[] = '| ' . implode(' | ', array_fill(0, count($headers), '---')) . ' |';

        foreach ($dataSheet->getRows() as $row) {
            $cells = [];
            foreach ($columnKeys as $key) {
                $cells[] = $this->escapeMarkdownCell($row[$key] ?? '');
            }
            $lines[] = '| ' . implode(' | ', $cells) . ' |';
        }

        return implode(PHP_EOL, $lines);
    }

    private function escapeMarkdownCell($value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            $text = (string) $value;
        } else {
            $text = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($text === false) {
                $text = '';
            }
        }

        $text = str_replace(["\r", "\n"], ' ', $text);
        return str_replace('|', '\\|', $text);
    }

    /**
     * 
     * 
     * @uxon-property table
     * @uxon-type DataTable
     * 
     * @param UxonObject $value
     * @return $this
     */
    protected function setTable(UxonObject $value): DataTableConcept
    {
        // Validate and normalize the table UXON via widget creation.
        $page = $this->getPrompt()->isTriggeredOnPage()
            ? $this->getPrompt()->getPageTriggeredOn()
            : UiPageFactory::createEmpty($this->getWorkbench());

        $widget = WidgetFactory::createFromUxon($page, $value, null, 'DataTable');

        if ($widget instanceof DataTable) {
            $this->dataTable = $widget;
        } else {
            throw new \InvalidArgumentException("The provided UXON does not represent a valid DataTable.");
        }
        
        return $this;
    }
}