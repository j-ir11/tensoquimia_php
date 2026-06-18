<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class RoleMiddleware
{
    /**
     * Asegura que el usuario tenga el rol requerido leyendo las habilidades del token de Sanctum
     */
    public function handle(Request $request, Closure $next, ...$roles)
    {
        $header = $request->header('Authorization');
        
        // 1. Validar que el formato del Bearer Token sea correcto
        if ($header && preg_match('/Bearer\s(\d+\|(.+))/', $header, $matches)) {
            $tokenPlano = $matches[2];
            
            // 2. Buscar el token hasheado en la tabla de Sanctum
            $tokenData = DB::table('personal_access_tokens')
                ->where('token', hash('sha256', $tokenPlano))
                ->first();

            if ($tokenData) {
                // 3. 🛡️ CONTROL DE EXPIRACIÓN: Si el token ya venció, forzar el 401 para que Zustand limpie la sesión
                $expiresAt = $tokenData->expires_at ? Carbon::parse($tokenData->expires_at) : null;
                if ($expiresAt && $expiresAt->isPast()) {
                    return response()->json(['message' => 'El token de sesión ha expirado'], 401);
                }

                // 4. Extraer el rol guardado dentro del array de 'abilities'
                $abilities = json_decode($tokenData->abilities, true);
                $userRole = $abilities[0] ?? null;

                // 5. Si el rol del usuario está dentro de los permitidos para la ruta, darle paso
                if (in_array($userRole, $roles)) {
                    return $next($request);
                }
                
                // Si el token es válido pero el rol no tiene permisos (ej: Vendedor queriendo borrar un producto)
                return response()->json(['message' => 'Acceso denegado: No tienes los privilegios requeridos para esta acción'], 403);
            }
        }

        // Si no mandó cabecera o el token no existe en la BD
        return response()->json(['message' => 'No autorizado: Token inválido o ausente'], 401);
    }
}