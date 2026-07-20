<?php
namespace axenox\GenAI\DataConnectors;

use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\DataSources\DataConnectionConfigurationError;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Mock connector for fully controlled HTTP responses.
 *
 * This connector keeps the normal OpenAiConnector/ClaudeConnector flow and only
 * overrides sendRequest() to return configured mock responses.
 */
class ClaudeErrorCasesConnector extends ClaudeConnector
{
	private ?string $mockUrl = null;

	private string $defaultPreset = 'none';

	private ?int $status = null;

	private ?string $httpStatusText = null;

	private ?string $requestId = null;

	private array $responseHeaders = [];

	private string|array|null $body = null;

	/**
	 * Optional preset for quick setup.
	 *
	 * Presets:
	 * - none
	 * - claude_overloaded
	 * - model_refusal
	 * - invalid_max_tokens
	 *
	 * All explicit properties (status, response_headers, body, http_status_text,
	 * request_id) override preset values.
	 *
	 * @uxon-property default_preset
	 * @uxon-type [none,claude_overloaded,model_refusal,invalid_max_tokens]
	 * @uxon-default none
	 */
	protected function setDefaultPreset(string $value) : ClaudeErrorCasesConnector
	{
		$this->defaultPreset = trim(strtolower($value));
		return $this;
	}

	/**
	 * Optional mock URL used by the parent request builder.
	 *
	 * If omitted, a local dummy URL is used so mocking works without a real endpoint.
	 *
	 * @uxon-property mock_url
	 * @uxon-type string
	 * @uxon-default https://mock.local/v1/messages
	 */
	protected function setMockUrl(string $value) : ClaudeErrorCasesConnector
	{
		$this->mockUrl = trim($value);
		return $this;
	}

	/**
	 * HTTP status to return.
	 *
	 * @uxon-property status
	 * @uxon-type integer
	 */
	protected function setStatus(int $value) : ClaudeErrorCasesConnector
	{
		$this->status = $value;
		return $this;
	}

	/**
	 * Optional HTTP reason phrase (e.g. OK, Bad Request).
	 *
	 * @uxon-property http_status_text
	 * @uxon-type string
	 */
	protected function setHttpStatusText(string $value) : ClaudeErrorCasesConnector
	{
		$this->httpStatusText = trim($value);
		return $this;
	}

	/**
	 * Optional request id mirrored to response headers/body.
	 *
	 * @uxon-property request_id
	 * @uxon-type string
	 */
	protected function setRequestId(string $value) : ClaudeErrorCasesConnector
	{
		$this->requestId = trim($value);
		return $this;
	}

	/**
	 * Response headers to return.
	 *
	 * @uxon-property response_headers
	 * @uxon-type object
	 */
	protected function setResponseHeaders(UxonObject|array $value) : ClaudeErrorCasesConnector
	{
		$this->responseHeaders = $value instanceof UxonObject ? $value->toArray() : $value;
		return $this;
	}

	/**
	 * Body to return (JSON object/array or plain string).
	 *
	 * @uxon-property body
	 * @uxon-type object|array|string
	 */
	protected function setBody(UxonObject|array|string $value) : ClaudeErrorCasesConnector
	{
		switch (true) {
			case $value instanceof UxonObject:
				$this->body = $value->toArray();
				break;
			default:
				$this->body = $value;
		}
		return $this;
	}

	protected function sendRequest(RequestInterface $request) : ResponseInterface
	{
		$config = $this->getMockResponseConfig();

		$status = (int) ($config['status'] ?? 200);
		$reasonPhrase = (string) ($config['http_status_text'] ?? '');
		$headers = $this->normalizeHeaders((array) ($config['headers'] ?? []));
		$bodyPayload = $config['body'] ?? [];

		if (! empty($config['request_id'])) {
			$requestId = (string) $config['request_id'];
			$headers['request-id'] = $requestId;
			$headers['x-request-id'] = $requestId;
			if (is_array($bodyPayload) && ! isset($bodyPayload['request_id'])) {
				$bodyPayload['request_id'] = $requestId;
			}
		}

		$body = is_string($bodyPayload)
			? $bodyPayload
			: json_encode($bodyPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

		if (! isset($headers['content-type'])) {
			$headers['content-type'] = is_string($bodyPayload) ? 'text/plain' : 'application/json';
		}

		$response = new Response($status, $headers, $body, '1.1', $reasonPhrase);

		if ($status >= 400) {
			$message = 'Mock HTTP error ' . $status . ($reasonPhrase !== '' ? ' ' . $reasonPhrase : '');
			throw new RequestException($message, $request, $response);
		}

		return $response;
	}

	/**
	 * Ensures a valid URL for the parent performQuery() request construction.
	 */
	protected function getUrl() : string
	{
		if ($this->mockUrl !== null && $this->mockUrl !== '') {
			return $this->mockUrl;
		}

		try {
			$url = parent::getUrl();
			if ($url !== '') {
				return $url;
			}
		} catch (\Throwable $e) {
			// Fall through to default mock URL.
		}

		return 'https://mock.local/v1/messages';
	}

	/**
	 * @return array<string,mixed>
	 */
	protected function getMockResponseConfig() : array
	{
		$preset = $this->getPresetConfig();

		return [
			'status' => $this->status ?? (int) ($preset['status'] ?? 200),
			'http_status_text' => $this->httpStatusText ?? (string) ($preset['http_status_text'] ?? ''),
			'request_id' => $this->requestId ?? ($preset['request_id'] ?? null),
			'headers' => empty($this->responseHeaders) ? ($preset['headers'] ?? []) : $this->responseHeaders,
			'body' => $this->body ?? ($preset['body'] ?? []),
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	protected function getPresetConfig() : array
	{
		switch ($this->defaultPreset) {
			case 'none':
				return [];
			case 'claude_overloaded':
				return [
					'status' => 529,
						'http_status_text' => 'Unknown',
						'request_id' => 'req_011CCkey4xczBt6wjZ9WLAZW',
					'headers' => [
							'content_type' => 'application/json',
							'x_should_retry' => true,
							'x_ratelimit_remaining_requests' => 249,
							'x_ratelimit_limit_requests' => 250,
							'x_ratelimit_remaining_tokens' => 249900,
							'x_ratelimit_limit_tokens' => 250000,
							'azureai_requested_tier' => 'default',
							'azureai_processed_tier' => 'default',
							'x_ms_region' => 'East US 2',
					],
					'body' => [
						'type' => 'error',
						'error' => [
							'type' => 'overloaded_error',
							'message' => 'Overloaded',
						],
							'request_id' => 'req_011CCkey4xczBt6wjZ9WLAZW',
					],
				];
			case 'model_refusal':
				return [
					'status' => 200,
						'http_status_text' => 'OK',
						'request_id' => 'req_011CckosDXCLZ9m2ohVDJ1Tx',
					'headers' => [
							'content_type' => 'application/json',
							'transfer_encoding' => 'chunked',
							'x_ratelimit_remaining_requests' => 249,
							'x_ratelimit_limit_requests' => 250,
							'x_ratelimit_remaining_tokens' => 249900,
							'x_ratelimit_limit_tokens' => 250000,
							'azureai_requested_tier' => 'default',
							'azureai_processed_tier' => 'default',
							'x_ms_region' => 'East US 2',
					],
					'body' => [
							'model' => 'claude-opus-4-8',
							'id' => 'msg_01C99LpDLi1Mz35mAmqC67it',
						'type' => 'message',
						'role' => 'assistant',
						'content' => [
							[
								'type' => 'text',
									'text' => '{"id":-789, "title":">JavON) diwe need to determthe how out I\'ve editopening it Git panel status"',
							],
						],
						'stop_reason' => 'refusal',
							'stop_sequence' => null,
						'stop_details' => [
							'type' => 'refusal',
							'category' => 'bio',
							'explanation' => null,
						],
							'usage' => [
								'input_tokens' => 28157,
								'output_tokens' => 608,
								'service_tier' => 'standard',
							],
					],
				];
			case 'invalid_max_tokens':
				return [
					'status' => 400,
						'http_status_text' => 'Bad Request',
						'request_id' => 'req_011Cciv5Y6B1BgnKS7QyQEW4',
					'headers' => [
							'content_type' => 'application/json',
							'content_length' => 215,
							'x_should_retry' => false,
							'x_ratelimit_remaining_requests' => 249,
							'x_ratelimit_limit_requests' => 250,
							'x_ratelimit_remaining_tokens' => 249900,
							'x_ratelimit_limit_tokens' => 250000,
							'azureai_requested_tier' => 'default',
							'azureai_processed_tier' => 'default',
							'x_ms_region' => 'East US 2',
					],
					'body' => [
						'type' => 'error',
						'error' => [
							'type' => 'invalid_request_error',
								'message' => 'max_tokens: 250000 > 128000, which is the maximum allowed number of output tokens for claude-opus-4-8',
						],
							'request_id' => 'req_011Cciv5Y6B1BgnKS7QyQEW4',
					],
				];
			default:
				return [];
		}
	}

	/**
	 * @param array<string,mixed> $headers
	 * @return array<string,string>
	 */
	protected function normalizeHeaders(array $headers) : array
	{
		$result = [];
		foreach ($headers as $name => $value) {
			$normalizedName = strtolower(str_replace('_', '-', (string) $name));
			switch (true) {
				case is_bool($value):
					$result[$normalizedName] = $value ? 'true' : 'false';
					break;
				case is_array($value):
					$result[$normalizedName] = implode(', ', array_map('strval', $value));
					break;
				default:
					$result[$normalizedName] = (string) $value;
			}
		}

		return $result;
	}

}

