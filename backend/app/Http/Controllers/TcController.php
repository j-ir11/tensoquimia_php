<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\CalculoService;

class TcController extends Controller
{
    /**
     * Obtener historial completo del tipo de cambio
     */
    public function getHistorialTC()
    {
        try {
            $rows = DB::table('historial_tipo_cambio')
                ->orderBy('fecha', 'desc')
                ->orderBy('id_tc', 'desc')
                ->get();

            return response()->json($rows);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al obtener historial TC'], 500);
        }
    }

    /**
     * Obtener el tipo de cambio más reciente
     */
    public function getTCActual()
    {
        try {
            $row = DB::table('historial_tipo_cambio')
                ->orderBy('fecha', 'desc')
                ->orderBy('id_tc', 'desc')
                ->first();

            if (!$row) {
                return response()->json(['id_tc' => null, 'fecha' => null, 'valor' => 18.00]);
            }

            return response()->json($row);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al obtener TC actual'], 500);
        }
    }

    /**
     * 🚀 ACTUALIZACIÓN MASIVA DE PRECIOS POR NUEVO TC (Ultra Veloz)
     */
    public function actualizarTipoCambioMasivo(Request $request)
    {
        $valor = $request->input('valor');

        if (!$valor || $valor <= 0) {
            return response()->json(['message' => 'Debe proporcionar un valor válido de TC'], 400);
        }

        // 1. Purgamos la caché estática antes de comenzar el proceso masivo
        CalculoService::limpiarCacheCostos();

        return DB::transaction(function () use ($valor) {
            $fechaActual = now()->toDateString();

            // 2. Insertar el nuevo Tipo de Cambio en la Base de Datos
            $id_tc_nuevo = DB::table('historial_tipo_cambio')->insertGetId([
                'fecha' => $fechaActual,
                'valor' => $valor
            ]);

            // 3. Obtener los IDs de todos los Productos Intermedios (PI) con fórmulas
            $pis = DB::table('productos as p')
                ->join('versiones_formula as v', 'p.id_producto', '=', 'v.id_producto')
                ->where('p.tipo_producto', 'PI')
                ->select('p.id_producto')
                ->distinct()
                ->get();

            // ⚡ 4. CLAVE DE RENDIMIENTO: Pre-cargamos todo el catálogo en la RAM de PHP
            // Esto evita que 'calcularCostoSintetizado' haga consultas repetitivas en la nube dentro del loop
            CalculoService::precargarCatalogo();

            $actualizaciones = 0;

            // 5. Procesar cada Producto Intermedio
            foreach ($pis as $pi) {
                $id_producto = $pi->id_producto;

                // Extraer la última versión registrada de ese producto
                $vAnt = DB::table('versiones_formula')
                    ->where('id_producto', $id_producto)
                    ->orderBy('numero_version', 'desc')
                    ->first();

                if (!$vAnt) {
                    continue;
                }

                // Calcular el nuevo costo en memoria (0 ms de latencia por consultas repetidas)
                $stack = [];
                $nuevoCosto = CalculoService::calcularCostoSintetizado($id_producto, $valor, $stack);

                // 6. Insertar la nueva versión consecutiva adaptada al cambio monetario
                $id_version_nueva = DB::table('versiones_formula')->insertGetId([
                    'id_producto'    => $id_producto,
                    'numero_version' => $vAnt->numero_version + 1,
                    'fecha'          => $fechaActual,
                    'id_tc'          => $id_tc_nuevo,
                    'nombre_proceso' => $vAnt->nombre_proceso,
                    'factor_proceso' => $vAnt->factor_proceso,
                    'costo_final'    => $nuevoCosto
                ]);

                // 7. Clonar ingredientes por lote usando SQL directo de inserción masiva
                DB::statement("
                    INSERT INTO ingredientes_formula (id_version, id_componente, porcentaje)
                    SELECT ?, id_componente, porcentaje 
                    FROM ingredientes_formula 
                    WHERE id_version = ?
                ", [$id_version_nueva, $vAnt->id_version]);

                // 8. Sincronizar el costo final calculado en el catálogo maestro
                DB::table('productos')
                    ->where('id_producto', $id_producto)
                    ->update(['costo' => $nuevoCosto]);

                $actualizaciones++;
            }

            // Purgamos de nuevo para que las siguientes peticiones HTTP lean los nuevos costos
            CalculoService::limpiarCacheCostos();

            return response()->json([
                'success' => true,
                'message' => 'Actualización masiva completada',
                'nuevo_tc' => $valor,
                'productos_actualizados' => $actualizaciones
            ]);
        });
    }
}