<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Attribute;
use App\Http\Resources\AttributeResource;

class AttributeController extends Controller
{
    public function index()
    {
        $attributes = Attribute::orderBy('group')
            ->orderBy('order')
            ->get();

        return AttributeResource::collection($attributes);
    }

}
