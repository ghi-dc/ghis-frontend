<?php

// src/Controller/SearchApiController.php

namespace App\Controller;

use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

#[Route('/api/search')]
class SearchApiController extends SearchController
{
    #[Route(path: '/total-items', methods: ['GET', 'POST'])]
    public function countAction(Request $request): JsonResponse
    {
        [$q, $filter] = $this->getQuery($request, array_keys($this->facets));

        [$pagination, $meta] = $this->doQuery($request, $q, $filter, self::PAGE_SIZE);

        if (is_null($pagination)) {
            $error = [
                '@context' => '/contexts/Error',
                '@type' => 'Error',
                'hydra:title' => 'An error occurred',
                'hydra:description' => 'Invalid query.',
            ];

            return new JsonResponse($error, JsonResponse::HTTP_BAD_REQUEST);
        }

        return new JsonResponse([
            'totalItems' => $pagination->getTotalItemCount(),
        ]);
    }
}
