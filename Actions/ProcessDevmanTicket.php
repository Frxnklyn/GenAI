<?php
namespace powerui\DevManCompanion\Actions;

use axenox\GenAI\Common\AiPrompt;
use axenox\GenAI\Factories\AiFactory;
use axenox\GenAI\Interfaces\AiAgentInterface;
use exface\Core\CommonLogic\AbstractAction;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\JsonDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Factories\DataSourceFactory;
use exface\Core\Factories\ResultFactory;
use exface\Core\Interfaces\DataSources\DataSourceInterface;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\Tasks\ResultInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\UrlDataConnector\Interfaces\HttpConnectionInterface;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Fetches tickets via DevMan API and passes them to a specified AI agent.
 * The agent processes the tickets and the modified tickets are then sent back to DevMan via the API.
 * 
 * @author Sergej Riel, Andrej Kabachnik
 */
class ProcessDevManTickets extends AbstractAction
{
    const RESULT_READY_TO_TEST = 'READY_TO_TEST';
    const RESULT_NEED_MORE_INPUT = 'NEED_MORE_INPUT';
    const RESULT_FAILED = 'FAILED';
    
    const TASK_PARAM_AGENT = 'agent';
    const TASK_PARAM_API_VERSION = 'api_version';
    const TASK_PARAM_API_DATA_SOURCE = 'api_data_source';
    const TASK_PARAM_ASSIGNEE = 'assignee';
    const TASK_PARAM_ASSIGN_FAILED_TO = 'assign_failed_to';
    const TASK_PARAM_RESPONSE_HEADING = 'response_heading';
    const DEFAULT_API_DATA_SOURCE_ALIAS = 'devman_api';
    
    private string $urlTicketsList = 'list?limit=100&offset=0&assigned_to=[#assigned_to_name#]';
    private string $urlTicketUpdate = 'save';
    private string $urlResourceList = 'list_resources?limit=100&offset=0';

    private ?string $agentAlias = null;
    
    private ?AiAgentInterface $agent = null;
    private ?string $apiDataSourseAlias = null;
    private ?DataSourceInterface $apiDataSource = null;
    private string $apiVersion = '1.0';

    private ?string $resourceForFailedTickets = null;
    private ?string $responseHeading = '## AI response';
    
    /**
     * @throws \JsonException
     */
    protected function perform(TaskInterface $task, DataTransactionInterface $transaction) : ResultInterface
    {
        $logbook = $this->getLogBook($task);
        
        if ($val = $task->getParameter(self::TASK_PARAM_API_DATA_SOURCE)) {
            $this->setDevmanApiDataSourceAlias($val);
        }
        if ($val = $task->getParameter(self::TASK_PARAM_API_VERSION)) {
            $this->setDevmanApiVersion($val);
        }
        if ($val = $task->getParameter(self::TASK_PARAM_AGENT)) {
            $this->setAgentAlias($val);
        }
        if ($val = $task->getParameter(self::TASK_PARAM_ASSIGNEE)) {
            // TODO
        }
        if ($val = $task->getParameter(self::TASK_PARAM_ASSIGN_FAILED_TO)) {
            $this->setAssignFailedTo($val);
        }
        if ($val = $task->getParameter(self::TASK_PARAM_RESPONSE_HEADING)) {
            $this->setResponseHeading($val);
        }
        if ($this->apiDataSourseAlias === null) {
            $this->setDevmanApiDataSourceAlias(self::DEFAULT_API_DATA_SOURCE_ALIAS);
        }
        
        // GET from Devman
        $getResponse = $this->getDevmanTickets($task);
        $responseBody = (string) $getResponse->getBody();
        $tickets = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
        $logbook->addLine('Received '  . count($tickets) . ' DevMan tickets');
        $logbook->addCodeBlock(JsonDataType::encodeJson($tickets, true), 'json');

        $agent = $this->getAgent();
        
        // Pass each ticket:
        $apiVersion = $this->getDevmanApiVersion();
        foreach ($tickets as &$ticket) {
            $ticketId = $ticket['id'];
            $author = $this->getDevmanResourceByUser($ticket['created_by_user']);

            $logbook->addSection('Ticket ' . $ticketId);
            $logbook->addLine('**' . $ticket['title'] . '**');

            $encodedTicket = $this->encodeTicketAsJson($ticket);
            $prompt = $this->getPrompt($encodedTicket);
            $answer = $agent->handle($prompt)->getJson();

            $logbook->addLine('AI responded with `' . $answer['result'] . '`');
            $logbook->addCodeBlock(JsonDataType::encodeJson($answer, true), 'json');

            if ($apiVersion === '1.0') {
                $update = $ticket;
                $update['id'] = $ticketId;
                $update['description'] = $answer['description'] ?? $ticket['description'];
                $update['state'] = $answer['state'] ?? $ticket['state'];
            } else {
                $update = [
                    'id' => $ticketId,
                    'title' => $ticket['title']
                ];

                $body = $ticket['description'];
                $responseHeading = $this->getResponseHeading($task);
                if (mb_stripos($body, $responseHeading) === false) {
                    $body .= "\n\n" . $responseHeading;
                }
                $body .= "\n\n" . $answer['result_description'];
                $update['description'] = $body;

                switch ($answer['result']) {

                    case self::RESULT_READY_TO_TEST:
                        $update['assigned_to'] = $author;
                        $update['state'] = 60;
                        break;

                    case self::RESULT_NEED_MORE_INPUT:
                        $update['assigned_to'] = $author;
                        $update['state'] = 45;
                        break;

                    case self::RESULT_FAILED:
                    default:
                        $update['assignee'] = $this->getAssigneeForFailedTickets($task) ?? $author;
                        $update['state'] = 75;
                        break;
                }
            }

            // POST to Devman
            $logbook->addLine('Updating ticket:');
            $logbook->addCodeBlock(JsonDataType::encodeJson($update, true), 'json');
            $postResponse = $this->postDevmanTickets($this->encodeTicketAsJson($update));
            $logbook->addLine('Got HTTP status code `' . $postResponse->getStatusCode() . '`');
        }
        
        return ResultFactory::createMessageResult(
            $task,
            'Processed ' . count($tickets) . ' DevMan tickets',
        );
    }

    /**
     * Gets the agent.
     * 
     * @return AiAgentInterface
     */
    protected function getAgent() : AiAgentInterface
    {
        if ($this->agent === null) {
            $agent = AiFactory::createAgentFromString($this->getWorkbench(), $this->agentAlias);
            $agent->setDevmode(true);
            $this->agent = $agent;
        }
        
        return $this->agent;
    }
    
    /**
     * Alias of the agent to prompt
     *
     * @uxon-property agent_alias
     * @uxon-type metamodel:axenox.GenAI.AI_AGENT:ALIAS_WITH_NS
     * @uxon-required true
     */
    protected function setAgentAlias(string $alias) : ProcessDevManTickets
    {
        $this->agentAlias = $alias;
        return $this;
    }

    /**
     * creates prompt with the given ticket as input for the agent.
     * 
     * @param $ticket
     * @return AiPrompt
     */
    protected function getPrompt($ticket) : AiPrompt
    {

        $prompt = new AiPrompt($this->getWorkbench());
        
        // Agent need a page. Change it to any page:
        $data['page_alias'] = 'axenox.genai.testing';
        $prompt->importUxonObject(new UxonObject($data));
        $prompt->setPrompt($ticket);

        return $prompt;
    }

    /**
     * @throws \JsonException
     */
    protected function encodeTicketAsJson($ticket) : string
    {
        return json_encode(
            [$ticket], 
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    /**
     * Fetches tickets from the DevMan dataflow API using a GET request.
     *
     * @param string $assigneeName
     * @return ResponseInterface
     */
    protected function getDevmanTickets(TaskInterface $task) : ResponseInterface
    {
        $assigneeName = $task->getParameter(self::TASK_PARAM_ASSIGNEE);
        $headers = $this->getDevmanRequestHeaders();
        $url = StringDataType::replacePlaceholders(
            $this->getUrl($this->urlTicketsList), 
            [
                'assigned_to_name' => urlencode($assigneeName)
            ],
            false
        );
        $request = new Request('GET', $url , $headers);
        return $this->sendRequest($request);
    }

    /**
     * Posts the $requestBody to DevMan API using a POST request.
     * 
     * @param string $requestBody
     * @return ResponseInterface
     */
    protected function postDevmanTickets(string $requestBody) : ResponseInterface
    {
        $headers = $this->getDevmanRequestHeaders();
        $request = new Request('POST', $this->getUrl($this->urlTicketUpdate), $headers, $requestBody);
        return $this->sendRequest($request);
    }

    /**
     * Gets Devman Ressource by user.
     * 
     * @param $user
     * @return ResponseInterface
     */
    protected function getDevmanResourceByUser($user) : ?int
    {
        $headers = $this->getDevmanRequestHeaders();
        $requestUrl = $this->getUrl($this->urlResourceList) . '&user=' . urlencode($user);
        $request = new Request('GET', $requestUrl , $headers);
        $userListResponse = $this->sendRequest($request);
        $userListResponseBody = (string) $userListResponse->getBody();
        $userListResponseBodyDecoded = json_decode($userListResponseBody, true, 512, JSON_THROW_ON_ERROR);

        $userId = $userListResponseBodyDecoded[0]['id'] ?? null;
        return $userId;
    }

    /**
     * Gets the Devman request headers.
     * 
     * @return string[]
     */
    protected function getDevmanRequestHeaders() : array
    {
        return [
            'accept' => '*/*',
            'Content-Type' => 'application/json'
        ];
    }
    
    /**
     * Sends a http request via the data source connection.
     * 
     * @param RequestInterface $request
     * @return ResponseInterface
     */
    protected function sendRequest(RequestInterface $request) : ResponseInterface
    {
        $connection = $this->getDevManApiSource()->getConnection();
        if (! $connection instanceof HttpConnectionInterface) {
            throw new \RuntimeException($connection::class . ' Cannot send HTTP request: data source connection does not support sending HTTP requests!');
        }
        return $connection->sendRequest($request);
    }

    /**
     * Gets the DevMan api source
     * 
     * @return DataSourceInterface
     */
    public function getDevManApiSource() : DataSourceInterface
    {
        if ($this->apiDataSource === null) {
            $this->apiDataSource = DataSourceFactory::createFromModel($this->getWorkbench(), $this->apiDataSourseAlias);
        }
        return $this->apiDataSource;
    }
    
    /**
     * Alias of the data source for the DevMan API
     * 
     * @uxon-property devman_api_data_source_alias
     * @uxon-type metamodel:data_source
     * @uxon-required true
     * 
     */
    protected function setDevmanApiDataSourceAlias(string $alias) : ProcessDevManTickets
    {
        $this->apiDataSourseAlias = $alias;
        return $this;
    }
    
    protected function getUrl(string $endpoint) : string
    {
        return 'tickets/' . $this->getDevmanApiVersion() . '/' . $endpoint;
    }

    protected function getDevmanApiVersion() : string
    {
        return $this->apiVersion;
    }
    
    protected function setDevmanApiVersion(string $version) : ProcessDevManTickets
    {
        $this->apiVersion = $version;
        return $this;
    }
    
    protected function getAssigneeForFailedTickets() : ?string
    {
        return $this->resourceForFailedTickets;
    }

    /**
     * @uxon-property assign_failed_to
     * @param string|null $resource
     * @return $this
     */
    protected function setAssignFailedTo(?string $resource) : ProcessDevManTickets
    {
        $this->resourceForFailedTickets = $resource;
        return $this;
    }
    
    protected function getResponseHeading() : string
    {
        return $this->responseHeading;
    }

    /**
     * Markdown heading to separate the AI response from when appending it to the ticket
     * 
     * @uxon-property response_heading
     * @uxon-type string
     * @uxon-default ## AI response
     * @uxon-template ## AI response
     * 
     * @param string $heading
     * @return $this
     */
    protected function setResponseHeading(string $heading) : ProcessDevManTickets
    {
        $this->responseHeading = $heading;
        return $this;
    }
}