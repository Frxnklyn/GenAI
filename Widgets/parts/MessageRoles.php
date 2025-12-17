<?php
namespace axenox\GenAI\Widgets\parts;

use exface\Core\Interfaces\Widgets\WidgetPartInterface;
use exface\Core\Widgets\Traits\DataWidgetPartTrait;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\Widgets\iShowData;

class MessageRoles implements WidgetPartInterface, iShowData
{
    use DataWidgetPartTrait;


    private string $ai = '';

     /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\iCanBeConvertedToUxon::exportUxonObject()
     */
    public function exportUxonObject()
    {
        return new UxonObject([]);
    }


    /**
     * 
     * 
     * @uxon-property ai
     * @uxon-type string
     * @uxon-required true
     * 
     * 
     * @param string $var
     * @return MessageRoles
     */
    protected function setAi(string $var) : MessageRoles
    {
        $this->ai = $var;
        
        return $this;
    }
}