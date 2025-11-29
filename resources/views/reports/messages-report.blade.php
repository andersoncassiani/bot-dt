<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Reporte de Mensajes - ChatSuite DT Growth Partners</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            color: #333;
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 3px solid #005F99;
        }

        .header h1 {
            color: #005F99;
            margin: 0;
        }

        .section {
            margin-bottom: 25px;
        }

        .section-title {
            background-color: #005F99;
            color: white;
            padding: 10px;
            font-size: 14px;
            font-weight: bold;
            margin-bottom: 15px;
        }

        .stats-grid {
            display: table;
            width: 100%;
            margin-bottom: 20px;
        }

        .stat-box {
            display: table-cell;
            width: 33%;
            padding: 15px;
            text-align: center;
            background-color: #f5f5f5;
            border: 1px solid #ddd;
        }

        .stat-number {
            font-size: 24px;
            font-weight: bold;
            color: #005F99;
        }

        .stat-label {
            font-size: 11px;
            color: #4A658F;
            margin-top: 5px;
        }

        .chart-bar {
            background-color: #005F99;
            height: 25px;
            margin: 5px 0;
            display: flex;
            align-items: center;
            padding-left: 10px;
            color: white;
            font-size: 11px;
        }

        .solicitud-item {
            margin-bottom: 15px;
            page-break-inside: avoid;
        }

        .solicitud-text {
            font-weight: bold;
            color: #333;
            margin-bottom: 5px;
        }

        .percentage {
            font-size: 18px;
            color: #005F99;
            font-weight: bold;
        }

        ul {
            margin: 10px 0;
            padding-left: 20px;
        }

        li {
            margin-bottom: 8px;
            line-height: 1.5;
        }

        .footer {
            position: fixed;
            bottom: 0;
            width: 100%;
            text-align: center;
            font-size: 10px;
            color: #999;
            border-top: 1px solid #ddd;
            padding-top: 10px;
        }
    </style>
</head>

<body>
    <div class="header">
        <img src="{{ public_path('images/logo-original.png') }}" alt="Logo" style="width: 120px; margin-bottom: 10px;">
        <h1>REPORTE DE ANÁLISIS DE MENSAJES</h1>
        <p style="margin: 5px 0;"> ChatSuite - DT Growth Partners </p>
        <p style="margin: 5px 0; font-size: 11px;">Generado el: {{ $fecha_generacion }}</p>
    </div>

    <!-- Estadísticas Generales -->
    <div class="section">
        <div class="section-title">ESTADÍSTICAS GENERALES</div>
        <div class="stats-grid">
            <div class="stat-box">
                <div class="stat-number">{{ number_format($stats['total_mensajes']) }}</div>
                <div class="stat-label">Total mensajes</div>
            </div>
            <div class="stat-box">
                <div class="stat-number">{{ number_format($stats['numeros_unicos']) }}</div>
                <div class="stat-label">Clientes unicos</div>
            </div>
            <div class="stat-box">
                <div class="stat-number">{{ number_format($stats['mensajes_mes']) }}</div>
                <div class="stat-label">Mensajes de este mes</div>
            </div>
        </div>
    </div>

    <!-- Solicitudes Más Frecuentes -->
    <div class="section">
        <div class="section-title">TOP 10 SOLICITUDES MÁS FRECUENTES</div>
        @if (isset($analisis['solicitudes_frecuentes']))
            @foreach ($analisis['solicitudes_frecuentes'] as $solicitud)
                <div class="solicitud-item">
                    <div class="solicitud-text">{{ $solicitud['solicitud'] }}</div>
                    <div style="display: flex; align-items: center;">
                        <div style="flex: 1;">
                            <div class="chart-bar" style="width: {{ $solicitud['porcentaje'] }}%;">
                                {{ $solicitud['porcentaje'] }}%
                            </div>
                        </div>
                        <div class="percentage" style="margin-left: 10px; width: 60px;">
                            {{ $solicitud['cantidad'] ?? 'N/A' }} veces
                        </div>
                    </div>
                </div>
            @endforeach
        @endif
    </div>

    <!-- Tendencias Identificadas -->
    @if (isset($analisis['tendencias']))
        <div class="section">
            <div class="section-title">TENDENCIAS IDENTIFICADAS</div>
            <ul>
                @foreach ($analisis['tendencias'] as $tendencia)
                    <li>{{ $tendencia }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <!-- Recomendaciones -->
    @if (isset($analisis['recomendaciones']))
        <div class="section">
            <div class="section-title">RECOMENDACIONES</div>
            <ul>
                @foreach ($analisis['recomendaciones'] as $recomendacion)
                    <li>{{ $recomendacion }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="footer">
        ChatSuite - DT Growth Partners
    </div>
</body>

</html>
