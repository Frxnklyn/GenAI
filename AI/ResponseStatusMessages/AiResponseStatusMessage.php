<?php
namespace axenox\GenAI\AI\ResponseStatusMessages;

use axenox\GenAI\Interfaces\AiResponseStatusMessageInterface;

/**
 * A simple status message for display in the AI response.
 * 
 * Implements the status message interface and handles rendering as HTML.
 */
class AiResponseStatusMessage implements AiResponseStatusMessageInterface
{
    private string $type;
    private string $text;
    private string $color;
    private string $role;

    public function __construct(string $type, string $text, string $color = '', string $role = 'ai')
    {
        $this->type = $type;
        $this->text = $text;
        $this->color = $color ?: $this->getDefaultColorForType($type);
        $this->role = $role;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function getColor(): string
    {
        return $this->color;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function buildHTML(): string
    {
        return '<div style="color:' . $this->color . '; font-weight:500;">' 
            . htmlspecialchars($this->text, ENT_QUOTES, 'UTF-8') 
            . '</div>';
    }

    private function getDefaultColorForType(string $type): string
    {
        return match ($type) {
            'ok', 'success' => 'green',
            'error', 'danger' => 'red',
            'warning' => 'orange',
            'info' => 'blue',
            default => 'black',
        };
    }
}
