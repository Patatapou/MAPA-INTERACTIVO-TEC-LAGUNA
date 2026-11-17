<?php
// ==========================================
// SERVICIO DE TOPOLOGÍA DE LA RED
// ==========================================
// Componentes conexas, grados de nodo y validación estructural.
//
// La topología se calcula con extremos compartidos y tolerancia de 2,5 m
// (apartados 10.5 y 16.2 del plan). No se usa para calcular rutas: eso lo
// hace Dijkstra sobre el grafo del cliente.

require_once __DIR__ . '/PathNetworkService.php';

class NetworkTopologyService
{
    private PathNetworkService $red;

    public function __construct(?PathNetworkService $red = null)
    {
        $this->red = $red ?? new PathNetworkService();
    }

    /**
     * Componentes conexas de la red.
     *
     * @return array{total: int, tamanos: array<int, int>, mayor: int, nodos: int, aristas: int}
     */
    public function componentesConexas(float $tolerancia = PathNetworkService::TOLERANCIA_CONTINUIDAD_M): array
    {
        $nodos = $this->red->nodos($tolerancia);
        $extremos = $this->red->extremosCanonicos($tolerancia);

        $adyacencia = [];
        foreach (array_keys($nodos) as $clave) {
            $adyacencia[$clave] = [];
        }

        $aristas = [];
        foreach ($extremos as $id => $extremo) {
            $a = $extremo['inicio'];
            $b = $extremo['fin'];
            if ($a === $b) {
                continue;
            }
            $adyacencia[$a][$b] = true;
            $adyacencia[$b][$a] = true;
            $aristas[strcmp($a, $b) < 0 ? "$a|$b" : "$b|$a"] = true;
        }

        $visitados = [];
        $tamanos = [];

        foreach (array_keys($adyacencia) as $inicio) {
            if (isset($visitados[$inicio])) {
                continue;
            }
            $tamano = 0;
            $pila = [$inicio];
            $visitados[$inicio] = true;

            while ($pila !== []) {
                $actual = array_pop($pila);
                $tamano++;
                foreach (array_keys($adyacencia[$actual] ?? []) as $vecino) {
                    if (!isset($visitados[$vecino])) {
                        $visitados[$vecino] = true;
                        $pila[] = $vecino;
                    }
                }
            }

            $tamanos[] = $tamano;
        }

        rsort($tamanos);

        return [
            'total' => count($tamanos),
            'tamanos' => $tamanos,
            'mayor' => $tamanos[0] ?? 0,
            'nodos' => count($adyacencia),
            'aristas' => count($aristas),
        ];
    }

    /**
     * Nodos con grado 1 (callejones sin salida).
     *
     * @return array<int, string>
     */
    public function nodosGrado1(float $tolerancia = PathNetworkService::TOLERANCIA_CONTINUIDAD_M): array
    {
        $resultado = [];
        foreach ($this->red->nodos($tolerancia) as $clave => $nodo) {
            if ($nodo['grado'] === 1) {
                $resultado[] = $clave;
            }
        }
        return $resultado;
    }

    /**
     * Nodos sin segmentos (no deberían existir si se derivan de la red).
     *
     * @return array<int, string>
     */
    public function nodosHuerfanos(float $tolerancia = PathNetworkService::TOLERANCIA_CONTINUIDAD_M): array
    {
        $resultado = [];
        foreach ($this->red->nodos($tolerancia) as $clave => $nodo) {
            if ($nodo['grado'] === 0) {
                $resultado[] = $clave;
            }
        }
        return $resultado;
    }

    /**
     * Validación estructural de la red.
     *
     * @return array{ok: bool, errores: array<int, string>, advertencias: array<int, string>, metricas: array}
     */
    public function validarRed(float $tolerancia = PathNetworkService::TOLERANCIA_CONTINUIDAD_M): array
    {
        $errores = [];
        $advertencias = [];

        $indice = $this->red->indice();
        $componentes = $this->componentesConexas($tolerancia);
        $grado1 = $this->nodosGrado1($tolerancia);

        if ($indice === []) {
            $errores[] = 'La red no tiene segmentos.';
        }

        $sinAristas = [];
        foreach ($indice as $id => $segmento) {
            if ($segmento['vertices'] < 2) {
                $errores[] = 'Segmento sin aristas: ' . $id . '.';
                continue;
            }
            if ($segmento['longitud_m'] <= 0.01) {
                $sinAristas[] = $id;
            }
        }
        if ($sinAristas !== []) {
            $advertencias[] = 'Segmentos de longitud cero: ' . implode(', ', $sinAristas) . '.';
        }

        if ($componentes['total'] > 1) {
            $advertencias[] = sprintf(
                'La red tiene %d componentes conexas (la mayor concentra %d de %d nodos).',
                $componentes['total'],
                $componentes['mayor'],
                $componentes['nodos']
            );
        }

        if ($grado1 !== []) {
            $advertencias[] = 'Nodos de grado 1 (callejones sin salida): ' . count($grado1) . '.';
        }

        $huerfanos = $this->nodosHuerfanos($tolerancia);
        if ($huerfanos !== []) {
            $advertencias[] = 'Nodos huérfanos (grado 0): ' . count($huerfanos) . '.';
        }

        return [
            'ok' => $errores === [],
            'errores' => $errores,
            'advertencias' => $advertencias,
            'metricas' => [
                'segmentos' => count($indice),
                'nodos' => $componentes['nodos'],
                'aristas' => $componentes['aristas'],
                'componentes' => $componentes['total'],
                'grado1' => count($grado1),
                'huerfanos' => count($huerfanos),
            ],
        ];
    }
}
