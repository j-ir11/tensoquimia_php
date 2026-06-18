<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\CalculoService;

class FormulaController extends Controller
{
    /**
     * Obtener historial de versiones de un producto
     */
    public function getVersionesByProducto($id_producto)
    {
        try {
            $versiones = DB::table('versiones_formula as v')
                ->leftJoin('historial_tipo_cambio as h', 'v.id_tc', '=', 'h.id_tc')
                ->select('v.*', 'h.valor as tc_valor')
                ->selectSub(function ($query) {
                    $query->from('ingredientes_formula')
                        ->whereColumn('id_version', 'v.id_version')
                        ->selectRaw('COUNT(*)');
                }, 'total_ingredientes')
                ->where('v.id_producto', $id_producto)
                ->orderBy('v.numero_version', 'desc')
                ->get();

            return response()->json($versiones);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al obtener versiones'], 500);
        }
    }

    /**
     * Obtener la versión más reciente de un producto (Mapeo compatible con React)
     */
    public function getUltimaVersion($id_producto)
    {
        try {
            // 1. Buscar la última versión usando tus nombres reales de tabla y columnas
            $version = DB::table('versiones_formula')
                ->where('id_producto', $id_producto)
                ->orderBy('numero_version', 'desc')
                ->first();

            // Si el producto no tiene fórmula previa aún, devolvemos nulo de forma limpia
            if (!$version) {
                return response()->json(null);
            }

            // 2. Jalar los ingredientes de forma directa
            $ingredientes = DB::table('ingredientes_formula')
                ->where('id_version', $version->id_version)
                ->select('id_componente', 'porcentaje') 
                ->get();

            // 3. ⚡ DOBLE COMPATIBILIDAD: Inyectamos las propiedades en la raíz Y anidadas
            return response()->json([
                'id_version'     => $version->id_version, 
                'id_producto'    => $version->id_producto,
                'nombre_proceso' => $version->nombre_proceso,
                'factor_proceso' => $version->factor_proceso,
                'costo_final'    => $version->costo_final,
                'version'        => $version, 
                'ingredientes'   => $ingredientes 
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error interno al cargar la última versión',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * 🚀 CREAR NUEVA VERSIÓN DE FÓRMULA (Congelando Costo Histórico)
     */
    public function createVersionFormula(Request $request)
    {
        return DB::transaction(function () use ($request) {
            $id_producto = $request->id_producto;
            $id_tc = $request->id_tc;
            $nombre_proceso = $request->nombre_proceso;
            $factor_proceso = $request->factor_proceso;
            
            $costoFinal = $request->input('costo_final', 0); 
            $ingredientes = $request->input('ingredientes', []);

            // Resolver ID del tipo de cambio si no viene explícito
            $tcIdToUse = $id_tc;
            if (!$tcIdToUse) {
                $lastTc = DB::table('historial_tipo_cambio')->orderBy('id_tc', 'desc')->first();
                $tcIdToUse = $lastTc ? $lastTc->id_tc : null;
            }

            // Calcular número consecutivo de la versión
            $maxv = DB::table('versiones_formula')->where('id_producto', $id_producto)->max('numero_version');
            $numero_version = ($maxv ?? 0) + 1;

            // Insertar versión maestro
            $id_version = DB::table('versiones_formula')->insertGetId([
                'id_producto' => $id_producto,
                'numero_version' => $numero_version,
                'fecha' => now()->toDateString(),
                'id_tc' => $tcIdToUse,
                'nombre_proceso' => $nombre_proceso,
                'factor_proceso' => $factor_proceso,
                'costo_final' => $costoFinal
            ]);

            // 🚀 MODIFICADO: Buscamos y guardamos el costo del catálogo en este preciso momento
            if (count($ingredientes) > 0) {
                $insertData = [];
                foreach ($ingredientes as $ing) {
                    $prodCosto = DB::table('productos')->where('id_producto', $ing['id_componente'])->value('costo');

                    $insertData[] = [
                        'id_version'                => $id_version,
                        'id_componente'             => $ing['id_componente'],
                        'porcentaje'                => $ing['porcentaje'],
                        'costo_historico_unitario'  => $prodCosto ?? 0 // 🔒 CONGELADO
                    ];
                }
                DB::table('ingredientes_formula')->insert($insertData);
            }

            // Actualizar catálogo de productos maestro
            DB::table('productos')->where('id_producto', $id_producto)->update(['costo' => $costoFinal]);

            // Purgar caché en memoria RAM
            CalculoService::limpiarCacheCostos();

            return response()->json([
                'message' => 'Guardado con éxito',
                'id_version' => $id_version,
                'numero_version' => $numero_version,
                'costo_final' => $costoFinal
            ], 201);
        });
    }

    /**
     * Obtener ingredientes de una versión
     */
    public function getIngredientesByVersion($id_version)
    {
        try {
            $ingredientes = DB::table('ingredientes_formula as i')
                ->join('productos as p', 'i.id_componente', '=', 'p.id_producto')
                ->select('i.*', 'p.clave_producto', 'p.descripcion_producto', 'p.tipo_producto', 'p.unidad_producto')
                ->where('i.id_version', $id_version)
                ->get();

            return response()->json($ingredientes);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al obtener ingredientes'], 500);
        }
    }

    /**
     * 🚀 ACTUALIZAR VERSIÓN ACTUAL (Sobrescribir y re-congelar costos)
     */
    public function actualizarVersionActual(Request $request, $id_producto)
    {
        return DB::transaction(function () use ($request, $id_producto) {
            $nombre_proceso = $request->nombre_proceso;
            $factor_proceso = $request->factor_proceso;
            $costoFinal = $request->input('costo_final', 0);
            $ingredientes = $request->input('ingredientes', []);

            $version = DB::table('versiones_formula')
                ->where('id_producto', $id_producto)
                ->orderBy('numero_version', 'desc')
                ->first();

            if (!$version) {
                return response()->json(['message' => 'No existe fórmula.'], 404);
            }

            $id_v = $version->id_version;

            // Borrar ingredientes anteriores
            DB::table('ingredientes_formula')->where('id_version', $id_v)->delete();
            
            // 🚀 MODIFICADO: Al sobrescribir la versión actual, actualizamos la memoria de costos
            if (count($ingredientes) > 0) {
                $insertData = [];
                foreach ($ingredientes as $ing) {
                    $prodCosto = DB::table('productos')->where('id_producto', $ing['id_componente'])->value('costo');

                    $insertData[] = [
                        'id_version'                => $id_v,
                        'id_componente'             => $ing['id_componente'],
                        'porcentaje'                => $ing['porcentaje'],
                        'costo_historico_unitario'  => $prodCosto ?? 0 // 🔒 RE-CONGELADO
                    ];
                }
                DB::table('ingredientes_formula')->insert($insertData);
            }

            // Actualizar versión maestro
            DB::table('versiones_formula')->where('id_version', $id_v)->update([
                'nombre_proceso' => $nombre_proceso,
                'factor_proceso' => $factor_proceso,
                'costo_final' => $costoFinal,
                'fecha' => now()->toDateString()
            ]);

            // Sincronizar catálogo principal
            DB::table('productos')->where('id_producto', $id_producto)->update(['costo' => $costoFinal]);

            CalculoService::limpiarCacheCostos();

            return response()->json(['message' => 'Actualizado con éxito', 'costo' => $costoFinal]);
        });
    }

    /**
     * Obtener reporte completo utilizando el costo capturado en el historial
     */
    public function getReporteCompletoVersion($id_version)
    {
        try {
            $versionMaster = DB::table('versiones_formula as v')
                ->join('productos as p', 'v.id_producto', '=', 'p.id_producto')
                ->join('historial_tipo_cambio as h', 'v.id_tc', '=', 'h.id_tc')
                ->select('v.*', 'p.clave_producto', 'p.descripcion_producto', 'p.unidad_producto', 'h.valor as tc_valor')
                ->where('v.id_version', $id_version)
                ->first();

            if (!$versionMaster) {
                return response()->json(['message' => 'No existe'], 404);
            }

            // 🚀 OPTIMIZADO: Lee la columna histórica. Si está vacía (datos viejos), recurre al costo maestro.
            $ingredientes = DB::table('ingredientes_formula as i')
                ->join('productos as p', 'i.id_componente', '=', 'p.id_producto')
                ->select([
                    'i.porcentaje', 
                    'p.clave_producto', 
                    'p.descripcion_producto', 
                    'p.unidad_producto', 
                    'p.moneda as moneda_base',
                    'p.tipo_producto',
                    DB::raw("COALESCE(i.costo_historico_unitario, p.costo) as costo_base_unitario")
                ])
                ->where('i.id_version', $id_version)
                ->get();

            return response()->json([
                'master' => $versionMaster,
                'ingredientes' => $ingredientes
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al obtener reporte'], 500);
        }
    }

    /**
     * Historial completo global
     */
    public function getHistorialCompleto()
    {
        try {
            $rows = DB::table('versiones_formula as v')
                ->join('productos as p', 'v.id_producto', '=', 'p.id_producto')
                ->leftJoin('historial_tipo_cambio as h', 'v.id_tc', '=', 'h.id_tc')
                ->select('v.*', 'h.valor as tc_valor', 'p.clave_producto', 'p.descripcion_producto')
                ->orderBy('p.clave_producto')
                ->orderBy('v.numero_version')
                ->get();

            return response()->json($rows);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al obtener historial'], 500);
        }
    }
}