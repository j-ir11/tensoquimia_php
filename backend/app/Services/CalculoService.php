<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class CalculoService
{
    protected static $cacheCostos = [];
    protected static $tablaProductosMemory = null;
    protected static $tablaVersionesMemory = null;     // ⚡ RAM
    protected static $tablaIngredientesMemory = null;   // ⚡ RAM
    protected static $ultimoTCVal = null;

    public static function redondearCostoEstricto($valor)
    {
        $num = (float) $valor;
        if ($num < 0) return 0;

        $multiplicado = $num * 100;
        $parteEntera = floor($multiplicado);
        $residuoDecimal = $multiplicado - $parteEntera;

        return ($residuoDecimal >= 0.49999) 
            ? (float) number_format(($parteEntera + 1) / 100, 2, '.', '') 
            : (float) number_format($parteEntera / 100, 2, '.', '');
    }

    public static function getUltimoTCValor()
    {
        if (self::$ultimoTCVal !== null) return self::$ultimoTCVal;

        try {
            $row = DB::table('historial_tipo_cambio')
                ->orderBy('fecha', 'desc')
                ->orderBy('id_tc', 'desc')
                ->first();

            self::$ultimoTCVal = $row ? (float) $row->valor : 18.00;
            return self::$ultimoTCVal;
        } catch (\Exception $e) {
            return 18.00;
        }
    }

    /**
     * ⚡ PRECARGA MASIVA ULTRA OPTIMIZADA:
     * Trae productos, últimas versiones e ingredientes a la RAM de un solo golpe.
     */
    public static function precargarCatalogo()
    {
        // 1. Cargar Productos
        if (self::$tablaProductosMemory === null) {
            $prods = DB::table('productos')->get();
            self::$tablaProductosMemory = [];
            foreach ($prods as $p) {
                self::$tablaProductosMemory[$p->id_producto] = $p;
            }
        }

        // 2. Cargar Últimas Versiones de Fórmulas en un solo Query
        if (self::$tablaVersionesMemory === null) {
            $subQuery = DB::table('versiones_formula')
                ->select('id_producto', DB::raw('MAX(numero_version) as max_version'))
                ->groupBy('id_producto');

            $versiones = DB::table('versiones_formula as v')
                ->joinSub($subQuery, 'latest', function ($join) {
                    $join->on('v.id_producto', '=', 'latest.id_producto')
                         ->on('v.numero_version', '=', 'latest.max_version');
                })->get();

            self::$tablaVersionesMemory = [];
            $idVersionesActivas = [];

            foreach ($versiones as $v) {
                self::$tablaVersionesMemory[$v->id_producto] = $v;
                $idVersionesActivas[] = $v->id_version;
            }

            // 3. Cargar todos los ingredientes de estas versiones activas de un viaje
            self::$tablaIngredientesMemory = [];
            if (!empty($idVersionesActivas)) {
                $ingredientes = DB::table('ingredientes_formula')
                    ->whereIn('id_version', $idVersionesActivas)
                    ->get();

                foreach ($ingredientes as $ing) {
                    self::$tablaIngredientesMemory[$ing->id_version][] = $ing;
                }
            }
        }
    }

    public static function calcularCostoSintetizado($id_producto, $tcManual = null, &$stack = [])
    {
        try {
            if (in_array($id_producto, $stack)) return 0;
            if (isset(self::$cacheCostos[$id_producto])) return self::$cacheCostos[$id_producto];

            // Ejecuta la precarga masiva (solo consultará a Aiven la primera vez)
            self::precargarCatalogo();
            $stack[] = $id_producto;

            $prod = self::$tablaProductosMemory[$id_producto] ?? null;
            if (!$prod) {
                array_pop($stack);
                return 0;
            }

            $tcActual = $tcManual ?? self::getUltimoTCValor();

            if ($prod->tipo_producto === 'MP') {
                $costoBase = (float) ($prod->costo ?? 0);
                $costoFinal = $prod->moneda === 'USD' 
                    ? self::redondearCostoEstricto($costoBase * $tcActual) 
                    : $costoBase;

                self::$cacheCostos[$id_producto] = $costoFinal;
                array_pop($stack);
                return $costoFinal;
            }

            // ⚡ CERO LATENCIA: Leemos la versión directo desde la memoria RAM de PHP
            $versionActual = self::$tablaVersionesMemory[$id_producto] ?? null;

            if (!$versionActual) {
                array_pop($stack);
                return 0;
            }

            // ⚡ CERO LATENCIA: Leemos los ingredientes directo desde la memoria RAM de PHP
            $ingredientes = self::$tablaIngredientesMemory[$versionActual->id_version] ?? [];

            $costoMezcla = 0;
            foreach ($ingredientes as $ing) {
                $costoComp = self::calcularCostoSintetizado($ing->id_componente, $tcActual, $stack);
                $aporteFila = self::redondearCostoEstricto($costoComp * ((float) $ing->porcentaje / 100));
                $costoMezcla += $aporteFila;
            }

            $resultado = self::redondearCostoEstricto($costoMezcla + (float) ($versionActual->factor_proceso ?? 0));
            self::$cacheCostos[$id_producto] = $resultado;
            array_pop($stack);
            return $resultado;

        } catch (\Exception $e) {
            array_pop($stack);
            return 0;
        }
    }

    public static function limpiarCacheCostos()
    {
        self::$cacheCostos = [];
        self::$tablaProductosMemory = null;
        self::$tablaVersionesMemory = null;      // Limpiamos RAM
        self::$tablaIngredientesMemory = null;    // Limpiamos RAM
        self::$ultimoTCVal = null;
    }
}