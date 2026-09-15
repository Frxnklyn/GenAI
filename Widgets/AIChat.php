<?php
namespace axenox\GenAI\Widgets;

use exface\Core\CommonLogic\UxonObject;
use axenox\GenAI\Facades\AiChatFacade;
use exface\Core\Factories\FacadeFactory;
use exface\Core\Interfaces\Widgets\iContainOtherWidgets;
use exface\Core\Widgets\InputCustom;
use exface\Core\Interfaces\Widgets\iFillEntireContainer;
use axenox\GenAI\Factories\AiFactory;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Widgets\parts\MessageRoles;

/**
 * Chat with AI assistants
 * 
 * 
 * @author Andrej Kabachnik
 *
 */
class AIChat extends InputCustom implements iFillEntireContainer
{
    private $aiChatFacade = null;

    private $agentAlias = null;

    private array $promptSuggestionsWidget = [];

    private ?AiAgentInterface $agent = null;

    private $buttons = [];

    private ?UxonObject $resetButton = null;

    private ?UxonObject $feedbackButton = null;

    private string $introMessage = '';

    private ?UxonObject $messageStyles = null;

    private bool $uploadEnabled = false;

    private array $allowedFileExtensions = [];

    // Enabled by default so every AIChat gets a history dropdown without extra UXON config.
    private ?UxonObject $conversationHistory = null;

    private bool $conversationHistoryEnabled = true;

    protected function init()
    {
        $this->setHideCaption(true);
        
        $this->setIncludeJsModules(['vendor/npm-asset/deep-chat/dist/deepChat.bundle.js']);
        $this->setCssClass('exf-aichat');
        
        // TODO why was this necessary although we used chatStyles below (they did not work in UI5)
        $this->setCss(<<<CSS
deep-chat {border: none !important;}
CSS

        );
        
        $this->setScriptToResize(<<<JS
        
            setTimeout(function(jqSelf){
                var jqParent = jqSelf.parent();
                var iHeightP = jqParent.innerHeight();
                var iWidthP = jqParent.innerWidth();
                // Resize the wrapper (not the deep-chat element itself) and let its flexbox layout
                // hand the remaining space to deep-chat - avoids fragile manual sibling-height math.
                if (iHeightP > 0 && Math.abs(iHeightP - jqSelf.height()) > 4) {
                    jqSelf.height(iHeightP);
                }
                if (iWidthP > 0 && iWidthP > (jqSelf.width() + 4)) {
                    jqSelf.width(iWidthP);
                }
                // DeepChat reads its own inline style.height attribute to size its shadow DOM and
                // ignores the fact that flexbox already stretched its rendered box - so the inline
                // height has to be written explicitly every time, even if it looks unchanged.
                var jqChat = jqSelf.find('#{$this->getIdOfDeepChat()}');
                var iSiblingsHeight = 0;
                jqChat.siblings().each(function(){
                    iSiblingsHeight += $(this).outerHeight(true);
                });
                var iChatHeight = jqSelf.height() - iSiblingsHeight;
                if (iChatHeight > 0) {
                    jqChat.height(iChatHeight);
                }
            }, 100, $('#{$this->getIdOfWrapper()}'));
JS);
        
        // Get/set value
        $this->setScriptToSetValue("$('#{$this->getIdOfDeepChat()}').data('exf-value', [#~mValue#])");
        $this->setScriptToGetValue("$('#{$this->getIdOfDeepChat()}').data('exf-value')");

        // Disable/enable
        $this->setScriptToDisable("(function(domEl){ if (domEl && domEl.disableSubmitButton !== undefined) domEl.disableSubmitButton()})($('#{$this->getIdOfDeepChat()}')[0]);");
        $this->setScriptToEnable("(function(domEl){ if (domEl && domEl.disableSubmitButton !== undefined) domEl.disableSubmitButton(false)})($('#{$this->getIdOfDeepChat()}')[0]);");
    }
    
    protected function getIdOfDeepChat() : string
    {
        return $this->getId() . '_deepchat';
    }

    protected function getIdOfWrapper() : string
    {
        return $this->getId() . '_wrapper';
    }

    protected function getIdOfHistorySelect() : string
    {
        return $this->getId() . '_history_select';
    }

    protected function getIdOfHistoryBadge() : string
    {
        return $this->getId() . '_history_badge';
    }

    /**
     * Adds a dropdown to load previous conversations of the current user and shows the currently
     * loaded conversation as a small badge.
     *
     * @uxon-property conversation_history
     * @uxon-type UxonObject
     * @uxon-template {"enabled": true}
     *
     * @param UxonObject $var
     * @return AIChat
     */
    protected function setConversationHistory(UxonObject $var) : AIChat
    {
        $this->conversationHistory = $var;
        $this->conversationHistoryEnabled = $var->getProperty('enabled') ?? true;
        return $this;
    }

    // Enabled by default - only explicit `conversation_history: {enabled: false}` turns it off.
    protected function isConversationHistoryEnabled() : bool
    {
        return $this->conversationHistoryEnabled;
    }

    protected function getHistoryToolbarHtml() : string
    {
        if (! $this->isConversationHistoryEnabled()) {
            return '';
        }

        return <<<HTML
            <div
            class='exf-aichat-history'
            style="display:flex; flex-direction:row; flex-shrink:0; gap:8px; align-items:center; margin-bottom:6px; box-sizing:border-box;">
                <select id='{$this->getIdOfHistorySelect()}' style="max-width:60%; padding:4px 6px; border-radius:6px; border:1px solid #ccc; box-sizing:border-box;">
                    <option value="">Neue Unterhaltung</option>
                </select>
                <span
                id='{$this->getIdOfHistoryBadge()}'
                class="exf-aichat-history-badge"
                style="display:inline-flex; align-items:center; padding:2px 10px; border-radius:12px; background:#eef2ff; color:#3730a3; font-size:12px; font-weight:500; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                    Neue Unterhaltung
                </span>
            </div>
        HTML;
    }

    protected function buildHtmlDeepChat() : string
    {
        $top = $this->getButtonsHTML('top');
        $botton = $this->getButtonsHTML('botton');

        $requestDataJs = '';
        if ($this->isBoundToAttribute()) {
            $requestDataJs = <<<JS
                requestDetails.body.data = {
                    oId: "{$this->getMetaObject()->getId()}", 
                    rows: [
                        { {$this->getAttributeAlias()}: $("#{$this->getIdOfDeepChat()}").data("exf-value") }
                    ]
                }
JS;
        }
        
        $historyToolbar = $this->getHistoryToolbarHtml();

        return <<<HTML

        <div id='{$this->getIdOfWrapper()}' class="deep-chat-wrapper" style="height: 100%; display:flex; flex-direction:column; box-sizing:border-box;">
            {$historyToolbar}
            <div
            class='exf-aichat-top'
            style="display:flex; flex-direction:row; flex-shrink:0; gap:8px; align-items:center;">
                {$top}
            </div>
            <deep-chat 
                mixedFiles='{$this->getMixedFilesAttributeValue()}'
                id='{$this->getIdOfDeepChat()}'
                class='exf-aichat'
                style="flex:1 1 auto; min-height:0;"
                connect='{
                    "url": "{$this->getAiChatFacade()->buildUrlToFacade()}/{$this->getAgentAlias()}/deepchat",
                    "method": "POST",
                    "additionalBodyProps": {
                        "object": "{$this->getMetaObject()->getAliasWithNamespace()}",
                        "page": "{$this->getPage()->getAliasWithNamespace()}",
                        "widget": "{$this->getId()}"
                    }
                }'
                responseInterceptor  = 'function (message) {
                    var domEl = document.getElementById("{$this->getIdOfDeepChat()}");

                    if (message.errorMessage && !message.error) {
                        message.error = message.errorMessage;
                    }else{
                        domEl.conversationId = message.conversation;
                        if (window['exfAichatOnResponse_' + domEl.id]) {
                            window['exfAichatOnResponse_' + domEl.id](message.conversation);
                        }
                    }
                    
                    
                    const messages = [message];

                    if (Array.isArray(message.additionalMessages)) {
                        messages.push(...message.additionalMessages);
                    }
                
                    return messages;   
                }'
                requestInterceptor = 'function (requestDetails) {
                    var domEl = document.getElementById("{$this->getIdOfDeepChat()}");
                    requestDetails.body.conversation = domEl.conversationId;
                    {$requestDataJs};
                    return requestDetails;
                }'

                errorMessages='{
                    "displayServiceErrorMessages": true,
                    "overrides": {
                    "default": "Fehler bitte erneut versuchen",
                    "service": "Dienstfehler",
                    "speechToText": "Spracherkennung fehlgeschlagen"
                    }
                }'

                introMessage='{$this->getIntroMessage()}'
                chatStyle='{"background": "transparent", "border": "none"}'
                messageStyles='{
                    "default": {
                      "shared": {
                        "bubble": { "maxWidth": "90%" }
                      }
                    },
                    "html": {
                        "shared": {
                            "bubble": {
                                "backgroundColor": "unset", 
                                "padding": "0px"
                            }
                        }
                    }
                  }'
            ></deep-chat>

            <div
            class='exf-aichat-top'
            style="display:flex; flex-direction:row; gap:8px; align-items:center;">
                {$botton}
            </div>

            
        </div>
    HTML;
    }

    protected function buildJsDeepChatInit() : string
    {
        $suggestions = '';
        foreach ($this->getPromptSuggestions() as $s){
            $suggestions .= ($suggestions ? ', ' : '') . "{ html: `<button class=\"deep-chat-button deep-chat-suggestion-button\" style=\"border-style: dashed\">{$s}</button>`, role: 'ai' }";
        }
        $introMessage = $this->getIntroMessage();

        return <<<JS

        (function () { 
            window.resetDeepChat = resetDeepChat;
            const chat = document.getElementById('{$this->getIdOfDeepChat()}');                
            if (!chat) {
              console.error("AIChat element not found in DOM");
              return;
            }
            
            chat.historyInitDone = false;
            chat.addEventListener('render', () => {
                if (chat.historyInitDone) return;
                chat.historyInitDone = true;
        
                chat.history = [
                    {$suggestions}
                ];

                initPreCopyObserver(chat);
            });
            
            function resetDeepChat(chatId) {
                const domEl = document.getElementById(chatId);
                if (domEl) {
                    domEl.conversationId = null;
                    domEl.messages = [];
                    domEl.history = [
                        {$suggestions}
                    ];
                    domEl.setAttribute('introMessage', '$introMessage');
                }
                if (typeof setAichatHistorySelection === 'function') {
                    setAichatHistorySelection(null);
                }
            }

            // Conversation history dropdown - lists the current user's previous conversations for
            // this agent and loads the selected one's messages into the chat.
            const historySelectEl = document.getElementById('{$this->getIdOfHistorySelect()}');
            const historyBadgeEl = document.getElementById('{$this->getIdOfHistoryBadge()}');
            const historyBaseUrl = '{$this->getAiChatFacade()->buildUrlToFacade()}';
            // Mutable so a page can switch the agent (e.g. via a table selection) and still load its history.
            let historyAgentAlias = '{$this->getAgentAlias()}';
            const historyNoConversationLabel = 'Neue Unterhaltung';

            function getHistoryListUrl() {
                return historyBaseUrl + '/' + historyAgentAlias + '/conversations';
            }

            function getHistoryMessagesUrl() {
                return historyBaseUrl + '/' + historyAgentAlias + '/conversations/messages';
            }

            function formatAichatHistoryLabel(title, date) {
                const t = (title && title.trim() !== '') ? title : 'Ohne Titel';
                return date ? (t + ' – ' + date) : t;
            }

            function setAichatHistoryBadge(text) {
                if (historyBadgeEl) {
                    historyBadgeEl.textContent = text;
                }
            }

            function setAichatHistorySelection(conversationId, label) {
                if (historySelectEl) {
                    historySelectEl.value = conversationId || '';
                }
                setAichatHistoryBadge(label || historyNoConversationLabel);
            }

            function loadAichatHistoryList(selectConversationId) {
                if (!historySelectEl) {
                    return;
                }
                fetch(getHistoryListUrl())
                    .then(r => r.json())
                    .then(data => {
                        const conversations = data.conversations || [];
                        historySelectEl.innerHTML = '';
                        const emptyOption = document.createElement('option');
                        emptyOption.value = '';
                        emptyOption.textContent = historyNoConversationLabel;
                        historySelectEl.appendChild(emptyOption);
                        conversations.forEach((c) => {
                            const opt = document.createElement('option');
                            opt.value = c.id;
                            opt.textContent = formatAichatHistoryLabel(c.title, c.date);
                            historySelectEl.appendChild(opt);
                        });
                        if (selectConversationId) {
                            const match = conversations.find((c) => c.id === selectConversationId);
                            setAichatHistorySelection(selectConversationId, match ? formatAichatHistoryLabel(match.title, match.date) : null);
                        }
                    })
                    .catch((err) => console.error('Could not load conversation history', err));
            }

            function loadAichatConversation(conversationId) {
                if (!conversationId) {
                    resetDeepChat(chat.id);
                    return;
                }
                // Clear the current chat first, just like switching the agent does, so old
                // messages don't linger while the selected conversation is being fetched.
                resetDeepChat(chat.id);
                fetch(getHistoryMessagesUrl() + '?conversation=' + encodeURIComponent(conversationId))
                    .then(r => r.json())
                    .then(data => {
                        chat.conversationId = data.conversation;
                        chat.messages = data.messages || [];
                        setAichatHistorySelection(data.conversation, formatAichatHistoryLabel(data.title, data.date));
                    })
                    .catch((err) => console.error('Could not load conversation', err));
            }

            if (historySelectEl) {
                historySelectEl.addEventListener('change', () => {
                    loadAichatConversation(historySelectEl.value || null);
                });
                loadAichatHistoryList();
            }

            // Refresh the dropdown once a response created/used a conversation, so its title
            // becomes visible without a manual reload.
            window['exfAichatOnResponse_' + chat.id] = function(conversationId) {
                loadAichatHistoryList(conversationId);
            };

            // Called by pages that let the user pick a different agent (e.g. an agents table),
            // so the history dropdown keeps listing conversations of the currently selected agent.
            window['exfAichatSetAgent_' + chat.id] = function(agentAliasWithVersion) {
                if (!agentAliasWithVersion) {
                    return;
                }
                historyAgentAlias = agentAliasWithVersion;
                loadAichatHistoryList();
            };

            // Called by pages that let the user pick a conversation from a table, so the chat,
            // the dropdown and the badge all stay in sync instead of jumping out of sync.
            window['exfAichatLoadConversation_' + chat.id] = function(conversationId) {
                loadAichatConversation(conversationId || null);
            };
        
            const input = document.getElementById("ratingInput");
            const stars = document.querySelectorAll("#stars span");
        
            function setRating(rating) {
                input.value = rating;
                stars.forEach((star, i) => {
                    star.textContent = i < rating ? "★" : "☆";
                    star.style.color = i < rating ? "gold" : "#bbb";
                });
                console.log('Send Rating');
            }
        
            stars.forEach((star, i) => {
                star.addEventListener("click", () => setRating(i + 1));
            });
            
            function addCopyButtonsToPre(root) {
                if (!root || !root.querySelectorAll) {
                    return;
                }
            
                root.querySelectorAll('pre').forEach((pre) => {
                    if (pre.dataset.copyButtonInitialized === 'true') {
                        return;
                    }
                    pre.dataset.copyButtonInitialized = 'true';
            
                    const parent = pre.parentNode;
                    if (!parent) {
                        return;
                    }
                    
                    pre.style.marginTop = '2px';
            
                    const wrapper = document.createElement('div');
                    wrapper.style.display = 'flex';
                    wrapper.style.flexDirection = 'column';
                    wrapper.style.alignItems = 'stretch';
                    wrapper.style.gap = '6px';
                    wrapper.style.margin = '8px 0';
            
                    const toolbar = document.createElement('div');
                    toolbar.style.display = 'flex';
                    toolbar.style.justifyContent = 'flex-end';
                    toolbar.style.alignItems = 'center';
            
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.textContent = 'Copy';
                    button.setAttribute('aria-label', 'Code kopieren');
                    button.style.display = 'inline-flex';
                    button.style.alignItems = 'center';
                    button.style.justifyContent = 'center';
                    button.style.height = '28px';
                    button.style.padding = '0 12px';
                    button.style.fontSize = '12px';
                    button.style.fontWeight = '500';
                    button.style.lineHeight = '1';
                    //button.style.boxShadow = '2px 2px 4px rgba(0,0,0,0.35)';
                    button.style.borderRadius = '8px';
                    button.style.cursor = 'pointer';
                    button.style.background = '#111';
                    button.style.color = '#fff';
                    //button.style.boxShadow = '0 2px 8px rgba(0,0,0,0.35)';
                    button.style.border = 'none';
                    button.style.outline = 'none';
                    button.style.backgroundClip = 'padding-box';
                    wrapper.style.gap = '0px';
                    toolbar.style.marginBottom = '2px';
                    
                    button.addEventListener('mouseenter', () => {
                        button.style.background = '#1b1b1b';
                    });
            
                    button.addEventListener('mouseleave', () => {
                        button.style.background = '#111';
                    });
            
                    button.addEventListener('click', async () => {
                        const codeEl = pre.querySelector('code');
                        const textToCopy = codeEl ? codeEl.innerText : pre.innerText.trim();
            
                        try {
                            await navigator.clipboard.writeText(textToCopy);
                            const originalText = button.textContent;
                            button.textContent = 'Copied';
                            setTimeout(() => {
                                button.textContent = originalText;
                            }, 1500);
                        } catch (error) {
                            console.error('Copy failed', error);
                            button.textContent = 'Error';
                            setTimeout(() => {
                                button.textContent = 'Copy';
                            }, 1500);
                        }
                    });
            
                    toolbar.appendChild(button);
            
                    parent.insertBefore(wrapper, pre);
                    wrapper.appendChild(toolbar);
                    wrapper.appendChild(pre);
                });
            }
            
            function initPreCopyObserver(chatEl) {
                if (!chatEl || chatEl.copyObserverInitDone) {
                    return;
                }
            
                const tryAttachObserver = () => {
                    const root = chatEl.shadowRoot;
                    if (!root) {
                        return false;
                    }
            
                    addCopyButtonsToPre(root);
            
                    const observer = new MutationObserver(() => {
                        addCopyButtonsToPre(root);
                    });
            
                    observer.observe(root, {
                        childList: true,
                        subtree: true
                    });
            
                    chatEl.copyObserverInitDone = true;
                    chatEl.copyObserver = observer;
                    return true;
                };
            
                if (tryAttachObserver()) {
                    return;
                }
            
                const waitForShadowRoot = () => {
                    if (tryAttachObserver()) {
                        return;
                    }
                    setTimeout(waitForShadowRoot, 100);
                };
            
                waitForShadowRoot();
            }
            
            initPreCopyObserver(chat);
        
        })();        


        /*

        Idea for how to send the Feedback
        
        
        function sendRating(chatId) {
            const el = document.getElementById(chatId);
            const rating = Number(document.getElementById("ratingInput").value || 0);

            const connect = {
                url: "{$this->getAiChatFacade()->buildUrlToFacade()}/{$this->getAgentAlias()}/rateChat",
                method: "POST",
                additionalBodyProps: {
                    object: "{$this->getMetaObject()->getAliasWithNamespace()}",
                    page: "{$this->getPage()->getAliasWithNamespace()}",
                    widget: chatId,
                    conversation: el?.conversationId ?? null,
                    rating
                }
            };

            fetch(connect.url, {
                method: connect.method,
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify(connect.additionalBodyProps)
            })
            .then(r => r.json())
            .then(data => {
                console.log("Antwort von rateChat:", data);
            })
            .catch(err => console.error("Fehler:", err));
        }
        
        
        */
JS;

    }

    protected function getButtonsHTML(string $position) : string 
    {
        $buttons = [];
        foreach($this->buttons as $btn){
            $buttons [] = $btn($position);
        }
        return implode("\n", $buttons);
    }

    /**
     * Alias of the agent to chat with - with namespace for agents from apps and without for local agents
     * 
     * @uxon-property agent_alias
     * @uxon-type metamodel:axenox.GenAI.AI_AGENT:ALIAS_WITH_NS
     * @uxon-required true
     * 
     * @param string $alias
     * @return AIChat
     */
    protected function setAgentAlias(string $alias) : AIChat
    {
        $this->agentAlias = $alias;
        return $this;
    }

    /**
     * 
     * @return string
     */
    public function getAgentAlias() : string
    {
        return $this->agentAlias;
    }

    /**
     * defines examples of suggestions for the Prompt
     * 
     * @uxon-property prompt_suggestions
     * @uxon-type array
     * @uxon-required true
     * @uxon-template [""]
     * 
     * @param UxonObject $alias
     * @return AIChat
     */
    protected function setPromptSuggestions(UxonObject $suggestions) : AIChat
    {
        $array = $suggestions->getPropertiesAll();
        foreach ($array as $s) {
            if (!is_string($s)) {
                
                continue;
            }
        }

        $this->promptSuggestionsWidget = $array;
        return $this;
    }

    protected function getPromptSuggestionsFromAgent() : array 
    {
        if(! $this->agent){
            $this->agent = AiFactory::createAgentFromString($this->getWorkbench(), $this->getAgentAlias());
        }
        return $this->agent->getPromptSuggestions();
    }

    public function getPromptSuggestions() : array
    {
        $all = array_merge(
        $this->promptSuggestionsWidget ?? [],
            $this->getPromptSuggestionsFromAgent() ?? []
        );

        return $all;

    }


    /*
    * Checks two standard properties of the given object ("position" and "enabled")
    * and returns true only if the object's position matches the given position
    * and it is marked as enabled (allowed to be used).
    */
    protected function canUseButton(UxonObject $var, string $position) : bool
    {
        $properties = $var->getPropertiesAll();
        $propPosition = strtolower($properties['position'] ?? '');
        $enabled = $properties['enabled'] ?? true;
        return $propPosition === strtolower($position) && $enabled;
    }

    /**
     * defines examples of suggestions for the Prompt
     * 
     * @uxon-property reset_button
     * @uxon-type UxonObject
     * @uxon-required true
     * @uxon-template {"enabled": true,"position": "top"}
     * 
     * @param UxonObject $alias
     * @return AIChat
     */
    protected function setResetButton(UxonObject $var) : AIChat
    {
        $this->resetButton = $var;
        $this->buttons[] = [$this,'getResetButtonHTML'];
        return $this;
    }

    protected function getResetButtonHTML(string $position) : string
    {
        $properties = $this->resetButton->getPropertiesAll();

        $order = ($properties['order'] ?? '1');

        if ($this->canUseButton($this->resetButton, $position)) {
            return <<<HTML
                    <button style="order : {$order}" type="button" onclick="resetDeepChat('{$this->getIdOfDeepChat()}')">Reset</button>
                    HTML;
                        }

        return '';
    }


    /**
     * defines examples of suggestions for the Prompt
     * 
     * @uxon-property feedback_window
     * @uxon-type UxonObject
     * @uxon-required true
     * @uxon-template {"enabled": true,"position": "top"}
     * 
     * @param UxonObject $alias
     * @return AIChat
     */
    protected function setFeedbackWindow(UxonObject $var) : AIChat
    {
        $this->feedbackButton = $var;
        $this->buttons[] = [$this,'getFeedbackWindow'];

        return $this;
    }

    protected function getFeedbackWindow(string $position) : string
    {

        $properties = $this->feedbackButton->getPropertiesAll();
        $order = ($properties['order'] ?? '2');


        if($this->canUseButton($this->feedbackButton, $position)){
            return <<<HTML
                    <div style="order:{$order}; display:flex; align-items:center; gap:12px;">
                        <input id="feedbackInput" type="text" placeholder="Dein Feedback..."
                            style="flex:1; padding:8px; font-size:16px; border:1px solid #ccc; border-radius:6px;" />

                        <div id="stars" style="font-size:30px; cursor:pointer; white-space:nowrap;">
                            <span id="s1" style="color:#bbb;">☆</span>
                            <span id="s2" style="color:#bbb;">☆</span>
                            <span id="s3" style="color:#bbb;">☆</span>
                            <span id="s4" style="color:#bbb;">☆</span>
                            <span id="s5" style="color:#bbb;">☆</span>
                        </div>
                    </div>

                    HTML;
        }
        return '';
    }

    /**
     * defines examples of suggestions for the Prompt
     * 
     * @uxon-property intro_message
     * @uxon-type string
     * @uxon-required true
     * 
     * 
     * @param string $alias
     * @return AIChat
     */
    protected function setIntroMessage(string $var) : AIChat
    {
        $this->introMessage = $var;
        
        return $this;
    }

    /**
     * Enable or disable uploads.
     *
     * @uxon-property upload_enabled
     * @uxon-type boolean
     * @uxon-default true
     *
     * @param bool $value
     * @return AIChat
     */
    public function setUploadEnabled(bool $value) : AIChat
    {
        $this->uploadEnabled = $value;
        return $this;
    }

    /**
     * Configure allowed upload file extensions.
     *
     * @uxon-property allowed_file_extensions
     * @uxon-type array
     * @uxon-required false
     * @uxon-template [".pdf"]
     *
     * @param array $value
     * @return AIChat
     */
    protected function setAllowedFileExtensions(array $value) : AIChat
    {
        $this->allowedFileExtensions = $this->normalizeAllowedFileExtensions($value);
        return $this;
    }

    /**
     * Normalize allowed file extensions to .ext lowercase strings without duplicates.
     *
     * @param mixed $formats
     * @return array
     */
    private function normalizeAllowedFileExtensions($formats) : array
    {
        if (is_string($formats)) {
            $formats = preg_split('/[\s,;]+/', $formats) ?: [];
        }

        if (! is_array($formats)) {
            return [];
        }

        $normalized = [];
        foreach ($formats as $format) {
            if (! is_string($format)) {
                continue;
            }

            $ext = trim(strtolower($format));
            if ($ext === '') {
                continue;
            }
            if ($ext[0] !== '.') {
                $ext = '.' . $ext;
            }
            if (! preg_match('/^\.[a-z0-9]+$/', $ext)) {
                continue;
            }

            $normalized[$ext] = true;
        }

        return array_keys($normalized);
    }

    protected function getMixedFilesAttributeValue() : string
    {
        if (! $this->uploadEnabled) {
            return 'false';
        }

        if (empty($this->allowedFileExtensions)) {
            return 'true';
        }

        $config = [
            'files' => [
                'acceptedFormats' => implode(',', $this->allowedFileExtensions)
            ]
        ];

        return json_encode($config, JSON_UNESCAPED_SLASHES) ?: 'true';
    }

    protected function getIntroMessage() : string
    {
        return '{"text": ' . json_encode($this->introMessage, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '}';
    }

    /**
     * defines examples of suggestions for the Prompt
     * 
     * @uxon-property message_styles
     * @uxon-type UxonObject
     * @uxon-required true
     * @uxon-template {}
     * 
     * @param UxonObject $uxon
     * @return AIChat
     */
    protected function setMessageStyles(UxonObject $var) : AIChat
    {
        //$test = new MessageRoles($this, $var);
        $this->messageStyles = $var;
        return $this;
    }
    

    /**
     * 
     * @return AiChatFacade
     */
    protected function getAiChatFacade() : AiChatFacade
    {
        if ($this->aiChatFacade === null) {
            $this->aiChatFacade = FacadeFactory::createFromString(AiChatFacade::class, $this->getWorkbench());
        }
        return $this->aiChatFacade;
    }

    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Widgets\iFillEntireContainer::getAlternativeContainerForOrphanedSiblings()
     */
    public function getAlternativeContainerForOrphanedSiblings() : ?iContainOtherWidgets
    {
        if ($this->getParent() && $this->getParent() instanceof iContainOtherWidgets) {
            return $this->getParent();
        }
        
        return null;
    }

    /**
     * Override getHtml() here to render the DeepChat domElement after all changes to widget and facade
     * element were definitely applied. Calling it inside init() is too early!
     * 
     * @see \exface\Core\Widgets\InputCustom::getHtml()
     */
    public function getHtml() : ?string
    {
        return $this->buildHtmlDeepChat();
    }

    /**
     *
     * @see InputCustom::getScriptToInit()
     */
    public function getScriptToInit() : ?string
    {
        return $this->buildJsDeepChatInit() . parent::getScriptToInit();
    }
}