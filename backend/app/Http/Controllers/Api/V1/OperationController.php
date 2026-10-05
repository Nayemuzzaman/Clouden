<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OperationResource;
use App\Models\Operation;

class OperationController extends Controller
{
    public function show(Operation $operation): OperationResource
    {
        return new OperationResource($operation);
    }
}
