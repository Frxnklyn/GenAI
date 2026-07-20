<?php
namespace axenox\GenAI\Common\ApiAdapters;

use axenox\GenAI\DataConnectors\ClaudeErrorCasesConnector;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Mock request adapter used by ClaudeErrorCasesConnector.
 *
 * It keeps normal request building behavior and injects preconfigured mock responses.
 */
class ClaudeErrorCasesMockRequestAdapter extends ClaudeMessagesApiRequestAdapter
{
    private ClaudeErrorCasesConnector $connector;

    public function __construct(ClaudeErrorCasesConnector $connector)
    {
        parent::__construct($connector);
        $this->connector = $connector;
    }

    public function buildMockResponseFromRequest(RequestInterface $request) : ResponseInterface
    {
        $case = $this->connector->getSelectedMockCase();
        $status = (int) ($case['http_status'] ?? 500);
        $body = $case['response_body'] ?? [
            'type' => 'error',
            'error' => [
                'type' => 'invalid_mock_case',
                'message' => 'The configured mock case has no response_body.',
            ],
        ];

        $headers = $this->connector->normalizeMockHeaders($case['response_headers'] ?? []);
        if (! isset($headers['content-type'])) {
            $headers['content-type'] = 'application/json';
        }

        if (! empty($case['request_id'])) {
            $headers['request-id'] = (string) $case['request_id'];
            $headers['x-request-id'] = (string) $case['request_id'];
        }

        if (! isset($body['request_id']) && ! empty($case['request_id'])) {
            $body['request_id'] = $case['request_id'];
        }

        $response = new Response($status, $headers, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        if ($status >= 400) {
            throw new RequestException($this->connector->buildMockFailureMessage($case), $request, $response);
        }

        return $response;
    }

    public function getDryrunResponse(array $requestJson, string $response) : ResponseInterface
    {
        return $this->buildMockResponseFromRequest($this->buildMockRequestFromJson($requestJson));
    }

    protected function buildMockRequestFromJson(array $requestJson) : RequestInterface
    {
        return new \GuzzleHttp\Psr7\Request(
            'POST',
            $this->connector->getMockUrl(),
            ['Content-Type' => 'application/json'],
            json_encode($requestJson, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }
}
