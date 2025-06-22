<?php

use App\Http\Controllers\AuthController;
use App\Http\Middleware\IsAdmin;
use App\Http\Middleware\IsUserAuth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');


/*PEDIENTE DE DEFINIR RUTAS PARA MUESTRA DE PRODUCTOS DE FORMA PUBLICA Y OTRAS REQUERIDAS*/
//Rutas publicas
Route::post('register', [AuthController::class, 'register']);
Route::post('login', [AuthController::class, 'login']);

Route::middleware(IsUserAuth::class)->group(function (){
    Route::controller(AuthController::class)->group(function (){
        Route::post('logout', 'logout');
        Route::post('me', 'getUser');
    });
});

Route::middleware(IsAdmin::class)->group(function (){

    /*
    PENDIENTE DE CAMBIOS EN CONTROLADORES 
    RETIRAR ENDPOINTS LUEGO 
    BORRAR COMENTARIO CUANDO SEA NECESARIO
    */
    Route::controller(AuthController::class)->group(function (){
        Route::post('logout', 'logout');
        Route::post('me', 'getUser');
    });


});


