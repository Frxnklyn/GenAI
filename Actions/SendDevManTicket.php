<?php
namespace axenox\GenAI\Actions;

use exface\Core\CommonLogic\AbstractAction;
use exface\Core\DataTypes\JsonDataType;
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
 * Creates a DevMan ticket via API.
 */
class SendDevManTicket extends AbstractAction
{
    const TASK_PARAM_API_VERSION = 'api_version';
    const TASK_PARAM_API_DATA_SOURCE = 'api_data_source';
    const TASK_PARAM_API_DATA_SOURCE_ALIAS = 'devman_api_data_source_alias';
    const TASK_PARAM_TITLE = 'title';
    const TASK_PARAM_DESCRIPTION = 'description';
    const TASK_PARAM_STATE = 'state';
    const DEFAULT_API_DATA_SOURCE_ALIAS = 'devman_api';
    const DEFAULT_API_VERSION = '2.0';
    const DEFAULT_ID = null;
    const DEFAULT_STATE = 10;
    const DEFAULT_TITLE = 'Ticket to demonstrate the API';
    const DEFAULT_DESCRIPTION = 'Create a ticket to demonstrate the Web API of the DevMan';

    private string $urlTicketCreate = 'save';

    private ?string $apiDataSourceAlias = null;
    private ?DataSourceInterface $apiDataSource = null;
    private string $apiVersion = self::DEFAULT_API_VERSION;

    /**
     * @throws \JsonException
     */
    protected function perform(TaskInterface $task, DataTransactionInterface $transaction) : ResultInterface
    {
        $logbook = $this->getLogBook($task);

        if ($val = $task->getParameter(self::TASK_PARAM_API_DATA_SOURCE)) {
            $this->setDevmanApiDataSourceAlias((string) $val);
        }
        if ($val = $task->getParameter(self::TASK_PARAM_API_DATA_SOURCE_ALIAS)) {
            $this->setDevmanApiDataSourceAlias((string) $val);
        }
        if ($val = $task->getParameter(self::TASK_PARAM_API_VERSION)) {
            $this->setDevmanApiVersion((string) $val);
        }
        if ($this->apiDataSourceAlias === null) {
            $this->setDevmanApiDataSourceAlias(self::DEFAULT_API_DATA_SOURCE_ALIAS);
        }

        try {
            $ticket = $this->buildTicketPayload($task);

            $payloadJson = $this->encodeTicketAsJson($ticket);
            $logbook->addLine('Creating DevMan ticket with direct save payload.');
            $logbook->addCodeBlock($payloadJson, 'json');

            $postResponse = $this->postDevmanTicket($payloadJson);
        } catch (\Throwable $e) {
            $logbook->addLine('Ticket create failed: ' . $e->getMessage());
            return ResultFactory::createMessageResult(
                $task,
                'DevMan ticket create failed: ' . $e->getMessage()
            );
        }

        $statusCode = $postResponse->getStatusCode();
        $responseBody = (string) $postResponse->getBody();

        $logbook->addLine('Got HTTP status code `' . $statusCode . '`');
        if ($responseBody !== '') {
            $logbook->addCodeBlock($responseBody, 'json');
        }

        return ResultFactory::createMessageResult(
            $task,
            'DevMan ticket created (HTTP ' . $statusCode . ')'
        );
    }

    /**
     * @throws \JsonException
     */
    protected function buildTicketPayload(TaskInterface $task) : array
    {
        $title = $task->getParameter(self::TASK_PARAM_TITLE);
        $description = $task->getParameter(self::TASK_PARAM_DESCRIPTION);
        $state = $task->getParameter(self::TASK_PARAM_STATE);

        return [
            'id' => self::DEFAULT_ID,
            'title' => ($title !== null && $title !== '') ? (string) $title : self::DEFAULT_TITLE,
            'description' => ($description !== null && $description !== '') ? (string) $description : self::DEFAULT_DESCRIPTION,
            'state' => ($state !== null && $state !== '') ? (int) $state : self::DEFAULT_STATE,
        ];
    }

    /**
     * @throws \JsonException
     */
    protected function encodeTicketAsJson(array $ticket) : string
    {
        return json_encode(
            [$ticket],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    protected function postDevmanTicket(string $requestBody) : ResponseInterface
    {
        $headers = $this->getDevmanRequestHeaders();
        $request = new Request('POST', $this->getUrl($this->urlTicketCreate), $headers, $requestBody);
        return $this->sendRequest($request);
    }

    /**
     * @return string[]
     */
    protected function getDevmanRequestHeaders() : array
    {
        return [
            'accept' => '*/*',
            'Content-Type' => 'application/json'
        ];
    }

    protected function sendRequest(RequestInterface $request) : ResponseInterface
    {
        $connection = $this->getDevManApiSource()->getConnection();
        if (! $connection instanceof HttpConnectionInterface) {
            throw new \RuntimeException($connection::class . ' Cannot send HTTP request: data source connection does not support sending HTTP requests!');
        }
        return $connection->sendRequest($request);
    }

    public function getDevManApiSource() : DataSourceInterface
    {
        if ($this->apiDataSource === null) {
            $this->apiDataSource = DataSourceFactory::createFromModel($this->getWorkbench(), $this->apiDataSourceAlias);
        }
        return $this->apiDataSource;
    }

    /**
     * Alias of the data source for the DevMan API
     *
     * @uxon-property devman_api_data_source_alias
     * @uxon-type metamodel:data_source
     * @uxon-required true
     */
    protected function setDevmanApiDataSourceAlias(string $alias) : SendDevManTicket
    {
        $this->apiDataSourceAlias = $alias;
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

    /**
     * @uxon-property api_version
     * @uxon-type string
        * @uxon-default 2.0
     */
    protected function setDevmanApiVersion(string $version) : SendDevManTicket
    {
        $this->apiVersion = $version;
        return $this;
    }
}
