<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\CalculoService;

class ProductoController extends Controller
{
    public function getProductos()
    {
        try {
            $productos = DB::table('productos')
                ->orderBy('tipo_producto')
                ->orderBy('clave_producto')
                ->get();

            return response()->json($productos);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al obtener productos'], 500);
        }
    }

    public function getProductoById($id)
    {
        try {
            $producto = DB::table('productos')->where('id_producto', $id)->first();
            if (!$producto) {
                return response()->json(['message' => 'Producto no encontrado'], 404);
            }
            return response()->json($producto);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al obtener producto'], 500);
        }
    }

    public function createProducto(Request $request)
    {
        try {
            $id = DB::table('productos')->insertGetId([
                'clave_producto'        => $request->clave_producto,
                'descripcion_producto'  => $request->descripcion_producto,
                'tipo_producto'         => $request->tipo_producto,
                'unidad_producto'       => $request->unidad_producto,
                'familia_producto'      => $request->familia_producto,
                'costo'                 => $request->costo ?? 0,
                'moneda'                => $request->moneda ?? 'MXN'
            ]);

            CalculoService::limpiarCacheCostos();

            return response()->json([
                'message' => 'Producto registrado en catálogo',
                'id_producto' => $id
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al crear producto'], 500);
        }
    }

   public function updateProducto(Request $request, $id)
{
    return DB::transaction(function () use ($request, $id) {
        // 1. Obtener los datos del producto antes de actualizar
        $productoAntes = DB::table('productos')->where('id_producto', $id)->first();
        
        if (!$productoAntes) {
            return response()->json(['message' => 'Producto no encontrado'], 404);
        }

        // 2. Ejecutar la actualización en el catálogo
        DB::table('productos')
            ->where('id_producto', $id)
            ->update([
                'clave_producto'        => $request->clave_producto,
                'descripcion_producto'  => $request->descripcion_producto,
                'tipo_producto'         => $request->tipo_producto,
                'unidad_producto'       => $request->unidad_producto,
                'familia_producto'      => $request->familia_producto,
                'costo'                 => $request->costo,
                'moneda'                => $request->moneda
            ]);

        // Limpiar caché inicial de costos
        CalculoService::limpiarCacheCostos();

        // 3. 🚀 DISPARADOR EN CASCADA EXTENDIDO
        // Se activa si el producto afectado ERA o AHORA ES una Materia Prima (MP) o un Producto Intermedio (PI)
        $tiposQueDisparanCascada = ['MP', 'PI'];

        if (in_array($productoAntes->tipo_producto, $tiposQueDisparanCascada) || 
            in_array($request->tipo_producto, $tiposQueDisparanCascada)) {
            
            $this->propagarNuevasVersionesPorCascada((int)$id);
        }

        return response()->json(['message' => 'Ficha de producto y fórmulas afectadas actualizadas con éxito']);
    });
}

    /**
     * 🔄 Método Auxiliar para propagar el efecto dominó hacia arriba (Modificado con Costo Histórico)
     */
    private function propagarNuevasVersionesPorCascada($id_componente)
    {
        // 1. Encontrar todos los Productos Intermedios (PI) que contienen directamente este componente
        $formulasAfectadas = DB::table('ingredientes_formula as i')
            ->join('versiones_formula as v', 'i.id_version', '=', 'v.id_version')
            ->join('productos as p', 'v.id_producto', '=', 'p.id_producto')
            ->where('i.id_componente', $id_componente)
            ->whereRaw('v.numero_version = (SELECT MAX(vf.numero_version) FROM versiones_formula vf WHERE vf.id_producto = v.id_producto)')
            ->select('v.id_producto', 'v.nombre_proceso', 'v.factor_proceso', 'v.id_version')
            ->get();

        foreach ($formulasAfectadas as $formula) {
            $id_pi = $formula->id_producto;

            // 2. Recalcular el costo del Producto Intermedio usando tu CalculoService
            $stack = [];
            $nuevoCostoPI = CalculoService::calcularCostoSintetizado($id_pi, null, $stack);

            // Obtener el último ID de tipo de cambio
            $lastTc = DB::table('historial_tipo_cambio')->orderBy('id_tc', 'desc')->first();
            $id_tc_a_usar = $lastTc ? $lastTc->id_tc : null;

            // Consecutivo de la versión del PI
            $maxv = DB::table('versiones_formula')->where('id_producto', $id_pi)->max('numero_version');
            $nuevaVersionNo = ($maxv ?? 0) + 1;

            // 3. Insertar la nueva versión del Producto Intermedio Maestro
            $nuevaVersionId = DB::table('versiones_formula')->insertGetId([
                'id_producto'    => $id_pi,
                'numero_version' => $nuevaVersionNo,
                'fecha'          => now()->toDateString(),
                'id_tc'          => $id_tc_a_usar,
                'nombre_proceso' => $formula->nombre_proceso,
                'factor_proceso' => $formula->factor_proceso,
                'costo_final'    => $nuevoCostoPI
            ]);

            // 4. 🚀 CLONAR E INYECTAR COSTOS CONGELADOS EN LA CASCADA:
            $ingredientesAnteriores = DB::table('ingredientes_formula')
                ->where('id_version', $formula->id_version)
                ->get();

            $nuevosIngredientes = [];
            foreach ($ingredientesAnteriores as $ing) {
                // Buscamos el costo actual que tiene este ingrediente específico en el catálogo maestro
                $prodCosto = DB::table('productos')->where('id_producto', $ing->id_componente)->value('costo');

                $nuevosIngredientes[] = [
                    'id_version'               => $nuevaVersionId,
                    'id_componente'            => $ing->id_componente,
                    'porcentaje'               => $ing->porcentaje,
                    'costo_historico_unitario' => $prodCosto ?? 0 // 🔒 CONGELADO EN CASCADA
                ];
            }

            if (count($nuevosIngredientes) > 0) {
                DB::table('ingredientes_formula')->insert($nuevosIngredientes);
            }

            // 5. Sincronizar el costo nuevo calculado en la lista maestra de productos
            DB::table('productos')->where('id_producto', $id_pi)->update(['costo' => $nuevoCostoPI]);

            // Purgar caché para asegurar que el siguiente nivel jerárquico lea el costo real actualizado
            CalculoService::limpiarCacheCostos();

            // 6. 🔥 LLAMADA RECURSIVA: Como este PI cambió, busquemos qué otros PI lo contienen a él
            $this->propagarNuevasVersionesPorCascada((int)$id_pi);
        }
    }

    public function deleteProducto($id)
    {
        try {
            DB::table('productos')->where('id_producto', $id)->delete();
            CalculoService::limpiarCacheCostos();
            return response()->json(['message' => 'Producto eliminado']);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'No se puede eliminar: el producto está siendo usado en fórmulas.'
            ], 500);
        }
    }

    public function getCostoActual($id)
    {
        try {
            $stack = [];
            $costo = CalculoService::calcularCostoSintetizado($id, null, $stack);

            $producto = DB::table('productos')->where('id_producto', $id)->first();
            if (!$producto) {
                return response()->json(['message' => 'Producto no encontrado'], 404);
            }

            return response()->json([
                'id_producto'     => (int) $id,
                'clave'           => $producto->clave_producto,
                'descripcion'     => $producto->descripcion_producto,
                'tipo'            => $producto->tipo_producto,
                'costo_calculado' => (float) number_format($costo, 4, '.', ''),
                'moneda'          => "MXN"
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error en el motor de costos'], 500);
        }
    }

    public function getCostosBatch(Request $request)
    {
        try {
            $ids = $request->input('ids', []);

            if (empty($ids)) {
                return response()->json([]);
            }

            $productos = DB::table('productos')
                ->whereIn('id_producto', $ids)
                ->select('id_producto', 'costo', 'moneda')
                ->get();

            $mapaCostos = [];
            foreach ($productos as $p) {
                $mapaCostos[$p->id_producto] = [
                    'costo'  => (float) $p->costo,
                    'moneda' => $p->moneda
                ];
            }

            return response()->json($mapaCostos);

        } catch (\Exception $e) {
            return response()->json(['message' => 'Error batch', 'error' => $e->getMessage()], 500);
        }
    }
}