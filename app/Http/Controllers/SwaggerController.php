<?php

namespace App\Http\Controllers;

use OpenApi\Attributes as OA;

#[OA\Info(
    title: 'FitAccess API',
    version: '1.0.0',
    description: 'FitAccess Employee Benefit Management System API',
    contact: new OA\Contact(email: 'admin@fitaccess.com')
)]
#[OA\Server(
    url: L5_SWAGGER_CONST_HOST,
    description: 'API Server'
)]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'JWT',
    description: 'Enter your Bearer token from login response'
)]
#[OA\Tag(name: 'Auth', description: 'Authentication endpoints')]
#[OA\Tag(name: 'Admin', description: 'Admin portal endpoints')]
#[OA\Tag(name: 'HR', description: 'HR portal endpoints')]
#[OA\Tag(name: 'Employee', description: 'Employee portal endpoints')]
#[OA\Tag(name: 'Partner', description: 'Partner/Gym portal endpoints')]
#[OA\Tag(name: 'Finance', description: 'Finance portal endpoints')]
class SwaggerController extends Controller
{
}
