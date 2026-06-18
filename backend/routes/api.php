<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProductoController;
use App\Http\Controllers\FormulaController;
use App\Http\Controllers\TcController;

/*
|--------------------------------------------------------------------------
| 🔓 RUTAS PÚBLICAS
|--------------------------------------------------------------------------
*/
Route::post('/login', [AuthController::class, 'login']);

/*
|--------------------------------------------------------------------------
| 🔒 RUTAS PROTEGIDAS (Control Total por Token y Rol)
|--------------------------------------------------------------------------
*/
Route::group([], function () {

    // Ruta de salida (Cerrar Sesión)
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('role:ADMIN,PRODUCCION,VENTAS');

    // ==========================================
    // 📦 RUTAS DE PRODUCTOS
    // ==========================================
    Route::prefix('productos')->group(function () {
        
        // 👁️ Lectura (ADMIN, VENTAS, PRODUCCION)
        Route::middleware('role:ADMIN,PRODUCCION,VENTAS')->group(function () {
            Route::get('/', [ProductoController::class, 'getProductos']);
            Route::get('/{id}', [ProductoController::class, 'getProductoById']);
            Route::get('/{id}/costo', [ProductoController::class, 'getCostoActual']);
        });

        // 📝 Escritura (SOLO ADMIN)
        Route::middleware('role:ADMIN')->group(function () {
            // ⚡ RUTAS ESTÁTICAS PRIMERO (Evita colisiones con /{id})
            Route::post('/', [ProductoController::class, 'createProducto']);
            Route::post('/costos-batch', [ProductoController::class, 'getCostosBatch']);
            
            // ⚡ RUTAS DINÁMICAS AL FINAL
            Route::put('/{id}', [ProductoController::class, 'updateProducto']);
            Route::delete('/{id}', [ProductoController::class, 'deleteProducto']);
        });
    });

    // ==========================================
    // 🧪 RUTAS DE FÓRMULAS
    // ==========================================
    Route::prefix('formulas')->group(function () {
        
        // 👁️ Lectura (ADMIN y PRODUCCION)
        Route::middleware('role:ADMIN,PRODUCCION')->group(function () {
            Route::get('/historial/todos', [FormulaController::class, 'getHistorialCompleto']);
            Route::get('/{id_producto}', [FormulaController::class, 'getVersionesByProducto']);
            Route::get('/{id_producto}/ultima', [FormulaController::class, 'getUltimaVersion']);
            Route::get('/version/{id_version}/ingredientes', [FormulaController::class, 'getIngredientesByVersion']);
            Route::get('/reporte/{id_version}', [FormulaController::class, 'getReporteCompletoVersion']);
        });

        // 📝 Escritura (SOLO ADMIN)
        Route::middleware('role:ADMIN')->group(function () {
            Route::post('/', [FormulaController::class, 'createVersionFormula']);
            Route::put('/{id_producto}', [FormulaController::class, 'actualizarVersionActual']);
        });
    });

    // ==========================================
    // 💵 RUTAS DE TIPO DE CAMBIO (TC)
    // ==========================================
    Route::prefix('tipo-cambio')->group(function () {
        
        // 👁️ Lectura (ADMIN, PRODUCCION, VENTAS)
        Route::middleware('role:ADMIN,PRODUCCION,VENTAS')->group(function () {
            Route::get('/', [TcController::class, 'getHistorialTC']);
            Route::get('/actual', [TcController::class, 'getTCActual']);
        });

        // 📝 Escritura (SOLO ADMIN)
        Route::post('/actualizar-masivo', [TcController::class, 'actualizarTipoCambioMasivo'])->middleware('role:ADMIN');
    });

});