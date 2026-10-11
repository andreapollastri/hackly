<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

abstract class ApiController extends Controller
{
    protected function perPage(Request $request): int
    {
        return min(100, max(1, $request->integer('per_page', 25)));
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    protected function domain(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (InvalidArgumentException|RuntimeException $e) {
            $this->fail($e->getMessage());
        }
    }

    protected function fail(string $message, int $status = 422): never
    {
        abort(response()->json(['message' => $message], $status));
    }

    /**
     * @param  class-string  $model
     * @param  list<string>  $ids
     */
    protected function assertIds(string $model, array $ids): void
    {
        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            return;
        }

        if ($model::query()->whereKey($ids)->count() !== count($ids)) {
            $this->fail('One or more related records were not found.');
        }
    }

    protected function noContent(): Response
    {
        return response()->noContent();
    }
}
