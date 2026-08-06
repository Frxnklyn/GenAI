<?php
namespace axenox\GenAI\AI\ResponseStatusMessages;

/**
 * Status message that presents a Yes / No confirmation dialog in the chat.
 *
 * The Yes button sends the special token `__confirmed__` to the DeepChat
 * widget; the No button sends `__cancelled__`. The agent detects these tokens
 * at the start of the next request and either executes or discards the
 * pending tool call accordingly.
 */
class AiResponseStatusMessageWithConfirmation extends AiResponseStatusMessage
{
    /** Token sent when the user clicks "Yes". */
    public const CONFIRM_TOKEN = '__confirmed__';

    /** Token sent when the user clicks "No". */
    public const CANCEL_TOKEN = '__cancelled__';

    public function __construct(string $question)
    {
        parent::__construct('info', $question, 'var(--deep-chat-user-message-color, #2563eb)');
    }

    public function buildHTML(): string
    {
        $question = htmlspecialchars($this->getText(), ENT_QUOTES, 'UTF-8');
        $confirmToken = self::CONFIRM_TOKEN;
        $cancelToken  = self::CANCEL_TOKEN;

        // After clicking, the button row is hidden so the user can only confirm/decline once.
        $hideAndSend = "function(btn,token){btn.parentElement.style.display='none';var dc=document.querySelector('deep-chat');if(dc)dc.submitUserMessage({text:token});}";

        return <<<HTML
<div style="display:flex; flex-direction:column; gap:10px; padding:4px 0;">
  <span style="font-weight:500;">{$question}</span>
  <div style="display:flex; gap:8px;">
    <button
      style="padding:6px 16px; border:none; border-radius:4px; background:#16a34a; color:#fff; cursor:pointer; font-size:13px;"
      onclick="({$hideAndSend})(this,'{$confirmToken}')">
      Yes, execute
    </button>
    <button
      style="padding:6px 16px; border:none; border-radius:4px; background:#dc2626; color:#fff; cursor:pointer; font-size:13px;"
      onclick="({$hideAndSend})(this,'{$cancelToken}')">
      No, cancel
    </button>
  </div>
</div>
HTML;
    }
}
