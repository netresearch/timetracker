# ADR-007: API Design Patterns

**Status:** Accepted  
**Date:** 2024-09-15  
**Deciders:** Architecture Team, Frontend Team  

## Context

The TimeTracker application requires a robust API design to support web interfaces, mobile applications, third-party integrations, and future expansion. The API must handle complex time tracking operations, JIRA synchronization, reporting, and multi-tenant access while maintaining consistency, performance, and developer experience.

### Requirements
- **RESTful Design**: Standard HTTP methods, resource-based URLs, consistent responses
- **API Versioning**: Support multiple API versions for backward compatibility
- **Authentication**: JWT-based authentication with role-based access control
- **Error Handling**: Consistent error responses with detailed information for debugging
- **Performance**: Response times <200ms for standard operations, <2s for complex reports
- **Documentation**: Auto-generated, interactive API documentation

### Current API Challenges
- Inconsistent response formats across endpoints
- No formal API versioning strategy
- Limited error information for client-side debugging
- Manual API documentation maintenance
- Performance bottlenecks in reporting endpoints

## Decision

We will implement **RESTful API design** with **semantic versioning**, **standardized error handling**, and **auto-generated documentation** using API Platform principles.

### API Design Principles

**1. Resource-Oriented Design**
```
GET    /api/v1/entries           # List entries
POST   /api/v1/entries           # Create entry
GET    /api/v1/entries/{id}      # Get specific entry
PUT    /api/v1/entries/{id}      # Update entry (full)
PATCH  /api/v1/entries/{id}      # Update entry (partial)
DELETE /api/v1/entries/{id}      # Delete entry

GET    /api/v1/entries/{id}/worklogs    # Get JIRA worklogs
POST   /api/v1/entries/{id}/sync        # Sync to JIRA
```

**2. Consistent Response Structure**
```json
{
  "data": {
    "id": 123,
    "type": "entry",
    "attributes": {
      "day": "2024-01-15",
      "duration": 480,
      "description": "Feature development"
    },
    "relationships": {
      "user": {"id": 45, "type": "user"},
      "project": {"id": 12, "type": "project"}
    }
  },
  "meta": {
    "version": "1.0",
    "timestamp": "2024-01-15T10:30:00Z"
  }
}
```

## Implementation Details

### 1. Versioning Strategy

**Semantic Versioning in URL Path:**
```php
#[Route('/api/v1/entries', methods: ['GET'])]
#[Route('/api/v2/entries', methods: ['GET'])] // Future version
class EntryController extends AbstractController
{
    public function list(Request $request): Response
    {
        $version = $this->getApiVersion($request);
        
        return match($version) {
            'v1' => $this->listV1($request),
            'v2' => $this->listV2($request),
            default => throw new NotSupportedException("API version {$version} not supported")
        };
    }
}
```

**Version-Specific Response Transformation:**
```php
class ApiResponseTransformer
{
    public function transformEntry(Entry $entry, string $version): array
    {
        return match($version) {
            'v1' => $this->transformEntryV1($entry),
            'v2' => $this->transformEntryV2($entry),
            default => throw new InvalidArgumentException("Unsupported version: {$version}")
        };
    }
    
    private function transformEntryV1(Entry $entry): array
    {
        return [
            'data' => [
                'id' => $entry->getId(),
                'type' => 'entry',
                'attributes' => [
                    'day' => $entry->getDay()->format('Y-m-d'),
                    'start' => $entry->getStart()->format('H:i'),
                    'end' => $entry->getEnd()->format('H:i'),
                    'duration' => $entry->getDuration(),
                    'description' => $entry->getDescription(),
                    'ticket' => $entry->getTicket(),
                    'billable' => $entry->isBillable(),
                ],
                'relationships' => [
                    'user' => [
                        'id' => $entry->getUser()->getId(),
                        'type' => 'user'
                    ],
                    'project' => [
                        'id' => $entry->getProject()->getId(),
                        'type' => 'project'
                    ],
                ],
            ],
            'meta' => [
                'version' => 'v1',
                'timestamp' => (new \DateTime())->format(\DateTime::ISO8601),
            ]
        ];
    }
}
```

### 2. Standardized Error Handling

**Centralized Error Response Format:**
```php
class ApiExceptionSubscriber implements EventSubscriberInterface
{
    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        $request = $event->getRequest();
        
        if (!$this->isApiRequest($request)) {
            return;
        }
        
        $response = match (true) {
            $exception instanceof ValidationException => $this->createValidationErrorResponse($exception),
            $exception instanceof NotFoundHttpException => $this->createNotFoundResponse($exception),
            $exception instanceof AccessDeniedException => $this->createForbiddenResponse($exception),
            $exception instanceof AuthenticationException => $this->createUnauthorizedResponse($exception),
            default => $this->createInternalServerErrorResponse($exception)
        };
        
        $event->setResponse($response);
    }
    
    private function createValidationErrorResponse(ValidationException $exception): JsonResponse
    {
        return new JsonResponse([
            'errors' => [
                'type' => 'validation_failed',
                'title' => 'Validation Failed',
                'detail' => 'The request data did not pass validation',
                'violations' => $exception->getViolations(),
            ],
            'meta' => [
                'timestamp' => (new \DateTime())->format(\DateTime::ISO8601),
                'request_id' => $this->getRequestId(),
            ]
        ], 422);
    }
    
    private function createNotFoundResponse(\Throwable $exception): JsonResponse
    {
        return new JsonResponse([
            'errors' => [
                'type' => 'resource_not_found',
                'title' => 'Resource Not Found',
                'detail' => 'The requested resource could not be found',
                'source' => [
                    'pointer' => $this->getResourcePath(),
                ]
            ],
            'meta' => [
                'timestamp' => (new \DateTime())->format(\DateTime::ISO8601),
                'request_id' => $this->getRequestId(),
            ]
        ], 404);
    }
}
```

### 3. Input Validation & Serialization

**Request DTOs with Validation:**
```php
class CreateEntryRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Date]
        public readonly string $day,
        
        #[Assert\NotBlank]
        #[Assert\Positive]
        public readonly int $duration,
        
        #[Assert\NotBlank]
        #[Assert\Length(min: 3, max: 255)]
        public readonly string $description,
        
        #[Assert\NotNull]
        #[Assert\Type('integer')]
        public readonly int $project,
        
        #[Assert\Length(max: 50)]
        public readonly ?string $ticket = null,
        
        #[Assert\Type('boolean')]
        public readonly bool $billable = true,
    ) {}
    
    public static function fromRequest(Request $request): self
    {
        $data = json_decode($request->getContent(), true);
        
        return new self(
            day: $data['day'] ?? '',
            duration: (int)($data['duration'] ?? 0),
            description: $data['description'] ?? '',
            project: (int)($data['project'] ?? 0),
            ticket: $data['ticket'] ?? null,
            billable: $data['billable'] ?? true,
        );
    }
}

#[Route('/api/v1/entries', methods: ['POST'])]
public function create(Request $request, ValidatorInterface $validator): Response
{
    $createRequest = CreateEntryRequest::fromRequest($request);
    
    $violations = $validator->validate($createRequest);
    if (count($violations) > 0) {
        throw new ValidationException($violations);
    }
    
    $entry = $this->entryService->createEntry($createRequest, $this->getUser());
    
    return new JsonResponse(
        $this->transformer->transformEntry($entry, 'v1'),
        201
    );
}
```

### 4. Pagination & Filtering

**Standardized Pagination:**
```php
class PaginatedResponse
{
    public function __construct(
        public readonly array $data,
        public readonly PaginationMetadata $pagination,
        public readonly array $filters = [],
    ) {}
}

class PaginationMetadata
{
    public function __construct(
        public readonly int $page,
        public readonly int $perPage,
        public readonly int $total,
        public readonly int $totalPages,
        public readonly ?string $nextPage = null,
        public readonly ?string $previousPage = null,
    ) {}
}

#[Route('/api/v1/entries', methods: ['GET'])]
public function list(Request $request): Response
{
    $page = max(1, (int)$request->query->get('page', 1));
    $perPage = min(100, max(10, (int)$request->query->get('per_page', 20)));
    
    $filters = [
        'date_from' => $request->query->get('date_from'),
        'date_to' => $request->query->get('date_to'),
        'project' => $request->query->get('project'),
        'user' => $request->query->get('user'),
    ];
    
    $result = $this->entryService->getPaginatedEntries(
        $this->getUser(),
        $page,
        $perPage,
        $filters
    );
    
    $pagination = new PaginationMetadata(
        page: $page,
        perPage: $perPage,
        total: $result->getTotalCount(),
        totalPages: (int)ceil($result->getTotalCount() / $perPage),
        nextPage: $page < $result->getTotalPages() 
            ? $this->generateUrl('api_entries_list', ['page' => $page + 1])
            : null,
        previousPage: $page > 1
            ? $this->generateUrl('api_entries_list', ['page' => $page - 1])
            : null,
    );
    
    return new JsonResponse([
        'data' => array_map(
            fn(Entry $entry) => $this->transformer->transformEntry($entry, 'v1')['data'],
            $result->getItems()
        ),
        'meta' => [
            'pagination' => $pagination,
            'filters' => array_filter($filters),
        ]
    ]);
}
```

### 5. Bulk Operations Support

**Batch Processing Endpoints:**
```php
#[Route('/api/v1/entries/bulk', methods: ['POST'])]
public function bulkCreate(Request $request): Response
{
    $data = json_decode($request->getContent(), true);
    
    if (!isset($data['entries']) || !is_array($data['entries'])) {
        throw new BadRequestException('Expected "entries" array in request body');
    }
    
    if (count($data['entries']) > 100) {
        throw new BadRequestException('Maximum 100 entries allowed per bulk request');
    }
    
    $results = [];
    $errors = [];
    
    foreach ($data['entries'] as $index => $entryData) {
        try {
            $createRequest = CreateEntryRequest::fromArray($entryData);
            $entry = $this->entryService->createEntry($createRequest, $this->getUser());
            
            $results[] = [
                'index' => $index,
                'status' => 'created',
                'data' => $this->transformer->transformEntry($entry, 'v1')['data']
            ];
            
        } catch (ValidationException $e) {
            $errors[] = [
                'index' => $index,
                'status' => 'validation_error',
                'errors' => $e->getViolations()
            ];
        } catch (\Exception $e) {
            $errors[] = [
                'index' => $index,
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }
    
    $statusCode = empty($errors) ? 201 : 207; // Multi-status for partial success
    
    return new JsonResponse([
        'results' => $results,
        'errors' => $errors,
        'summary' => [
            'total' => count($data['entries']),
            'successful' => count($results),
            'failed' => count($errors),
        ]
    ], $statusCode);
}
```

### 6. API Documentation

**OpenAPI/Swagger Integration:**
```php
#[OA\Get(
    path: '/api/v1/entries',
    summary: 'List time entries',
    description: 'Get paginated list of time entries for authenticated user with filtering options',
    tags: ['Entries']
)]
#[OA\Parameter(
    name: 'page',
    in: 'query',
    description: 'Page number for pagination',
    schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)
)]
#[OA\Parameter(
    name: 'per_page', 
    in: 'query',
    description: 'Number of items per page',
    schema: new OA\Schema(type: 'integer', minimum: 10, maximum: 100, default: 20)
)]
#[OA\Parameter(
    name: 'date_from',
    in: 'query',
    description: 'Filter entries from this date (YYYY-MM-DD)',
    schema: new OA\Schema(type: 'string', format: 'date')
)]
#[OA\Response(
    response: 200,
    description: 'Successful response with paginated entries',
    content: new OA\JsonContent(
        properties: [
            'data' => new OA\Property(
                type: 'array',
                items: new OA\Items(ref: '#/components/schemas/Entry')
            ),
            'meta' => new OA\Property(
                properties: [
                    'pagination' => new OA\Property(ref: '#/components/schemas/Pagination'),
                    'filters' => new OA\Property(type: 'object'),
                ]
            ),
        ]
    )
)]
#[Route('/api/v1/entries', methods: ['GET'])]
public function list(Request $request): Response
{
    // Implementation...
}
```

### 7. Performance Optimization

**Response Caching Headers:**
```php
class CacheableApiController extends AbstractController
{
    protected function createCacheableResponse(
        array $data,
        int $maxAge = 300,
        array $tags = [],
        ?string $etag = null
    ): JsonResponse {
        $response = new JsonResponse($data);
        
        if ($maxAge > 0) {
            $response->setMaxAge($maxAge);
            $response->setSharedMaxAge($maxAge);
            $response->setPublic();
        }
        
        if ($etag) {
            $response->setEtag($etag);
        }
        
        if ($tags) {
            $response->headers->set('Cache-Tags', implode(',', $tags));
        }
        
        // Add CORS headers for web clients
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
        
        return $response;
    }
}

#[Route('/api/v1/entries/{id}', methods: ['GET'])]
public function show(Entry $entry, Request $request): Response
{
    $data = $this->transformer->transformEntry($entry, 'v1');
    $etag = md5(json_encode($data) . $entry->getUpdatedAt()->getTimestamp());
    
    // Return 304 if client has current version
    if ($request->headers->get('If-None-Match') === $etag) {
        return new Response('', 304);
    }
    
    return $this->createCacheableResponse(
        $data,
        maxAge: 600, // 10 minutes
        tags: ["entry_{$entry->getId()}", "user_{$entry->getUser()->getId()}"],
        etag: $etag
    );
}
```

## Consequences

### Positive
- **Consistency**: Standardized response formats across all endpoints
- **Developer Experience**: Clear, predictable API with comprehensive documentation
- **Versioning**: Backward compatibility through semantic versioning
- **Performance**: Caching headers and efficient pagination reduce server load
- **Error Handling**: Detailed error responses improve client-side debugging
- **Scalability**: RESTful design supports horizontal scaling and caching

### Negative
- **Initial Complexity**: Comprehensive API design requires significant upfront effort
- **Documentation Maintenance**: OpenAPI annotations need to stay synchronized with code
- **Version Management**: Multiple API versions increase maintenance overhead
- **Response Size**: Detailed responses may increase bandwidth usage
- **Learning Curve**: Team needs to understand RESTful principles and OpenAPI

### API Security

**Authentication & Authorization:**
```php
class ApiSecuritySubscriber implements EventSubscriberInterface
{
    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        
        if (!$this->isApiRequest($request)) {
            return;
        }
        
        // Enforce HTTPS in production
        if ($this->environment === 'prod' && !$request->isSecure()) {
            throw new AccessDeniedException('API requires HTTPS');
        }
        
        // Rate limiting
        $this->rateLimiter->checkLimit($request->getClientIp());
        
        // JWT token validation
        $token = $this->extractBearerToken($request);
        if (!$token) {
            throw new AuthenticationException('Missing or invalid authorization header');
        }
        
        $user = $this->tokenService->validateToken($token);
        $request->attributes->set('user', $user);
    }
}
```

### Performance Monitoring

**API Metrics Collection:**
```php
class ApiMetricsSubscriber implements EventSubscriberInterface
{
    public function onKernelResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();
        
        if (!$this->isApiRequest($request)) {
            return;
        }
        
        $endpoint = $request->attributes->get('_route');
        $method = $request->getMethod();
        $statusCode = $response->getStatusCode();
        $responseTime = microtime(true) - $request->server->get('REQUEST_TIME_FLOAT');
        
        $this->metrics->increment('api.requests', [
            'endpoint' => $endpoint,
            'method' => $method,
            'status_code' => $statusCode,
        ]);
        
        $this->metrics->histogram('api.response_time', $responseTime * 1000, [
            'endpoint' => $endpoint,
            'method' => $method,
        ]);
        
        if ($responseTime > 2.0) { // Slow API call
            $this->logger->warning('Slow API response', [
                'endpoint' => $endpoint,
                'method' => $method,
                'response_time' => $responseTime,
            ]);
        }
    }
}
```

This comprehensive API design ensures consistency, performance, and maintainability while providing excellent developer experience and supporting future growth requirements.