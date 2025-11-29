<?php

use Illuminate\Support\Facades\Route;

//Para que el proyecto siempre por la URL o ruta de Pley y no por defecto la cual es /
Route::get('/', function () {
     return redirect('/chatsuite');
});