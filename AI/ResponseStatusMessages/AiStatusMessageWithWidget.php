<?php
namespace axenox\GenAI\AI\ResponseStatusMessages;

use axenox\GenAI\Interfaces\AiResponseStatusMessageInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Facades\AbstractAjaxFacade\AbstractAjaxFacade;
use exface\Core\Factories\ActionFactory;
use exface\Core\Factories\FacadeFactory;
use exface\Core\Interfaces\Actions\ActionInterface;
use exface\Core\Interfaces\Actions\iShowWidget;
use exface\Core\Interfaces\WidgetInterface;
use exface\Core\Exceptions\InvalidArgumentException;
use exface\Core\Exceptions\RuntimeException;

/**
 * Status message with a button that opens a server-rendered native PowerUI widget.
 *
 * @author Brooklyn Fränzschky
 */
class AiStatusMessageWithWidget implements AiResponseStatusMessageInterface
{
	private string $type;
	private string $text;
	private string $buttonLabel;
	private string $color;
	private string $role;
	private string $id;
	private ActionInterface $action;

	/**
	 * Creates the message from a configured action or Action UXON.
	 *
	 * Action UXON requires a context widget and receives an isolated ID space automatically.
	 */
	public function __construct(
		string $text,
		ActionInterface|UxonObject $action,
		string $buttonLabel = 'Details',
		string $type = 'info',
		string $color = '#2563eb',
		string $role = 'ai',
		?WidgetInterface $contextWidget = null,
		?string $id = null
	) {
		$this->id = $this->validateOrCreateId($id);
		$this->action = $action instanceof UxonObject
			? $this->createActionFromUxon($action, $contextWidget)
			: $action;

		if (! $this->action instanceof iShowWidget) {
			throw new InvalidArgumentException('An AI status message widget action must implement iShowWidget.');
		}

		$this->text = $text;
		$this->buttonLabel = $buttonLabel;
		$this->type = $type;
		$this->color = $color;
		$this->role = $role;
	}

	/**
	 * Returns the status type.
	 */
	public function getType(): string
	{
		return $this->type;
	}

	/**
	 * Returns the status text.
	 */
	public function getText(): string
	{
		return $this->text;
	}

	/**
	 * Returns the status color.
	 */
	public function getColor(): string
	{
		return $this->color;
	}

	/**
	 * Returns the chat role.
	 */
	public function getRole(): string
	{
		return $this->role;
	}

	/**
	 * Returns the configured PowerUI action.
	 */
	public function getAction(): ActionInterface
	{
		return $this->action;
	}

	/**
	 * Renders the trigger and embeds the trusted server-rendered widget fragment.
	 */
	public function buildHTML(): string
	{
		$text = htmlspecialchars($this->text, ENT_QUOTES, 'UTF-8');
		$buttonLabel = htmlspecialchars($this->buttonLabel, ENT_QUOTES, 'UTF-8');
		$fragment = htmlspecialchars(base64_encode($this->renderWidgetFragment()), ENT_QUOTES, 'UTF-8');
		$color = $this->getSafeColor();
		$id = htmlspecialchars($this->id, ENT_QUOTES, 'UTF-8');

		return <<<HTML
<div style="display:flex;align-items:center;gap:10px;color:{$color};font-weight:500;">
	<span>{$text}</span>
	<button
		id="{$id}"
		type="button"
		aria-haspopup="dialog"
		data-widget-fragment="{$fragment}"
		style="padding:6px 12px;border:0;border-radius:5px;background:{$color};color:#fff;cursor:pointer;font-weight:600;"
		onclick="var b=this;b.disabled=true;try{var bytes=Uint8Array.from(atob(b.dataset.widgetFragment),function(c){return c.charCodeAt(0);}),html=new TextDecoder('utf-8').decode(bytes),w=\$('<div class=&quot;ajax-wrapper&quot;></div>').append(html);if(\$('#ajax-dialogs').length===0){\$('body').append('<div id=&quot;ajax-dialogs&quot;></div>');}\$('#ajax-dialogs').append(w);var id=w.find('.easyui-dialog').first().attr('id');if(!id){w.remove();throw new Error('The native widget could not be rendered.');}\$.parser.parse(w);var d=\$('#'+id),close=d.panel('options').onClose;d.panel('options').onClose=function(){if(close){close.call(this);}\$(this).dialog('destroy').remove();w.remove();};d.dialog('open');}catch(e){console.error(e);jeasyui_show_error('Widget',e.message||'The native widget could not be loaded.','genai-status-widget');}finally{b.disabled=false;}"
	>{$buttonLabel}</button>
</div>
HTML;
	}

	/**
	 * Creates and configures an action while keeping every generated widget ID isolated.
	 */
	private function createActionFromUxon(UxonObject $actionUxon, ?WidgetInterface $contextWidget): ActionInterface
	{
		if ($contextWidget === null) {
			throw new InvalidArgumentException('Action UXON requires a context widget.');
		}

		$actionUxon = $actionUxon->copy();
		$widgetUxon = $actionUxon->getProperty('widget');
		if ($widgetUxon instanceof UxonObject) {
			if (! $widgetUxon->hasProperty('id')) {
				$widgetUxon->setProperty('id', $this->id . '_widget');
			}
			if (! $widgetUxon->hasProperty('id_space')) {
				$widgetUxon->setProperty('id_space', $this->id);
			}
			$actionUxon->setProperty('widget', $widgetUxon);
		}

		return ActionFactory::createFromUxon(
			$contextWidget->getWorkbench(),
			$actionUxon,
			$contextWidget
		);
	}

	/**
	 * Renders the action widget exactly like an AJAX widget result, including dependencies.
	 */
	private function renderWidgetFragment(): string
	{
		$widget = $this->action->getWidget();
		if ($widget === null) {
			throw new RuntimeException('The configured action does not provide a widget.');
		}

		$facade = FacadeFactory::createDefaultHttpFacade($this->action->getWorkbench());
		if (! $facade instanceof AbstractAjaxFacade) {
			throw new RuntimeException('The default HTTP facade cannot render a native widget fragment.');
		}

		return $facade->buildHtmlHead($widget, false) . "\n" . $facade->buildHtmlBody($widget);
	}

	/**
	 * Returns a restricted CSS color suitable for inline status styling.
	 */
	private function getSafeColor(): string
	{
		return preg_match('/^(?:#[0-9a-f]{3,8}|[a-z]{3,20})$/i', $this->color) === 1
			? $this->color
			: '#2563eb';
	}

	/**
	 * Returns a safe caller-provided ID or creates a collision-resistant one.
	 */
	private function validateOrCreateId(?string $id): string
	{
		$id = $id ?? 'genai_status_' . bin2hex(random_bytes(12));
		if (preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $id) !== 1) {
			throw new InvalidArgumentException('Invalid AI status message widget ID.');
		}

		return $id;
	}
}
