<?php
namespace axenox\GenAI\Common;
use exface\Core\CommonLogic\Tasks\HttpTask;
use axenox\GenAI\Interfaces\AiConversationInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\CommonLogic\Workbench;
use exface\Core\Factories\WidgetFactory;
use exface\Core\Interfaces\Security\AuthenticationTokenInterface;
use exface\Core\Interfaces\UserInterface;
use exface\Core\Widgets\DebugMessage;

class AiPrompt extends HttpTask implements AiPromptInterface
{
    private ?AiConversationInterface $conversation = null;

    private ?string $conversationUid = null;
    
    private $files = [];
    
    private array $konwledge = [];

    public function getMessages() : array
    {
        $params = $this->getParameters();
        return ($params['messages'] ?? $params['prompt']) ?? [];
    }

    public function getUserPrompt() : string
    {
        return implode(PHP_EOL, $this->getUserMessages());
    }

    public function getConversation() : ?AiConversationInterface
    {
        return $this->conversation;
    }

    public function setConversation(AiConversationInterface $conversation) : AiPromptInterface
    {
        $this->conversation = $conversation;
        $this->conversationUid = $conversation->getConversationId();
        return $this;
    }

    public function getConversationUid() : ?string
    {
        if ($this->conversation !== null) {
            return $this->conversation->getConversationId();
        }

        if ($this->conversationUid === null) {
            $params = $this->getParameters();
            $this->conversationUid = isset($params['conversation']) && is_string($params['conversation'])
                ? $params['conversation']
                : null;
        }

        return $this->conversationUid;
    }

    public function getUserMessages() : array
    {
        $array = array_filter($this->getMessages(), function($msg) {
            if (is_string($msg)) {
                return true;
            } else {
                return $msg['role'] === 'user';
            }
        });
        $result = [];
        foreach ($array as $msg) {
            $result[] = $msg['text'];
        }
        return $result;
    }

    public function getSystemMessages() : array
    {
        return array_filter($this->getMessages(), function($msg) {
            if (is_string($msg)) {
                return false;
            } else {
                return $msg['role'] === 'system';
            }
        });
    }

    /**
     * The user message for this prompt
     * 
     * @uxon-property prompt
     * @uxon-type string
     * 
     * @param string $text
     * @return AiPromptInterface
     */
    public function setPrompt(string $text) : AiPromptInterface
    {
        $msgs = [[
            'role' => 'user',
            'text' => $text
        ]];
        $this->setParameter('messages', $msgs);
        return $this;
    }

    /**
     * @param DebugMessage $debugWidget
     * @return DebugMessage
     */
    public function createDebugWidget(DebugMessage $debugWidget)
    {
        // Request
        $promptTab = $debugWidget->createTab();
        $promptTab->setCaption('AI Prompt');
        $promptTab->setWidgets(new UxonObject([[
            'widget_type' => 'Markdown',
            'width' => '100%',
            'height' => '100%',
            'hide_caption' => true,
            'value' => $this->toMarkdown(),
        ]]));
        $debugWidget->addTab($promptTab);
        return $debugWidget;
    }

    /**
     * @return string
     */
    protected function toMarkdown() : string
    {
        return <<<MD

Username: `{$this->getUser()->getUsername()}`

## User prompt

{$this->getUserPrompt()}
MD;

    }

    /**
     * @return UserInterface
     */
    protected function getUser() : UserInterface
    {
        return $this->getWorkbench()->getSecurity()->getAuthenticatedUser();
    }
    
    public function getFiles() : array
    {
        return $this->files;
    }
    
    public function setFiles(array $files) : AiPromptInterface
    {
        $this->files = $files;
        return $this;
    }


    /**
     * {@inheritDoc}
     * @see AiPromptInterface::hasKnowledge()
     */
    public function hasKnowledge(string $key) : bool
    {
        return array_key_exists($key, $this->konwledge);
    }

    /**
     * {@inheritDoc}
     * @see AiPromptInterface::addKnowledge()
     */
    public function addKnowledge(string $key, string $content) : AiPromptInterface
    {
        $this->konwledge[$key] = $content;
        return $this;
    }

    /**
     * {@inheritDoc}
     * @see AiPromptInterface::getKnowledge()
     */
    public function getKnowledge() : array
    {
        return $this->konwledge;
    }
}