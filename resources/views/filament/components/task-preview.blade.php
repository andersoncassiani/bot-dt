<div class="space-y-4">
    @php
        $userMap = $userMap ?? [
            'edgardo' => '573116123189',
            'dairo' => '573007189383',
            'stiven' => '573026444564',
        ];

        function getPhoneFromMap($name, $map)
        {
            $normalized = strtolower(trim($name));
            if (isset($map[$normalized])) {
                return $map[$normalized];
            }
            foreach ($map as $key => $phone) {
                if (str_contains($normalized, $key)) {
                    return $phone;
                }
            }
            return null;
        }
    @endphp

    @if ($error)
        <div class="p-4 bg-red-50 border border-red-200 rounded-lg">
            <p class="text-red-700 font-medium">❌ Error consultando API</p>
            <p class="text-red-600 text-sm">{{ $error }}</p>
        </div>
    @elseif(empty($tasks))
        <div class="p-4 bg-gray-50 border border-gray-200 rounded-lg text-center">
            <p class="text-gray-600">📭 No hay tareas de alta prioridad en la cola</p>
        </div>
    @else
        <div class="p-3 bg-blue-50 border border-blue-200 rounded-lg">
            <p class="text-blue-700 font-medium">📋 {{ $count }} tarea(s) pendiente(s)</p>
            <p class="text-blue-600 text-sm">Se notificará al asignado de cada tarea</p>
        </div>

        <div class="space-y-3 max-h-96 overflow-y-auto">
            @foreach ($tasks as $task)
                @php
                    $asignado = $task['asignado'] ?? 'Sin asignar';
                    $phone = getPhoneFromMap($asignado, $userMap);
                @endphp
                <div class="p-4 bg-white border border-gray-200 rounded-lg shadow-sm">
                    <div class="flex items-start justify-between">
                        <div class="flex-1">
                            <h4 class="font-semibold text-gray-900">{{ $task['titulo'] ?? 'Sin título' }}</h4>

                            @if (!empty($task['descripcion']))
                                <p class="text-gray-600 text-sm mt-1">{{ $task['descripcion'] }}</p>
                            @endif
                        </div>
                        <span class="px-2 py-1 text-xs font-medium bg-red-100 text-red-700 rounded">
                            🔴 {{ $task['prioridad'] ?? 'Alta' }}
                        </span>
                    </div>

                    <div class="mt-3 grid grid-cols-2 gap-2 text-sm">
                        <div>
                            <span class="text-gray-500">👤 Asignado:</span>
                            <span class="text-gray-900 font-medium">{{ $asignado }}</span>
                        </div>
                        <div>
                            <span class="text-gray-500">✍️ Creador:</span>
                            <span class="text-gray-900">{{ $task['creador'] ?? 'Sistema' }}</span>
                        </div>
                        @if (!empty($task['proyecto']))
                            <div>
                                <span class="text-gray-500">📁 Proyecto:</span>
                                <span class="text-gray-900">{{ $task['proyecto'] }}</span>
                            </div>
                        @endif
                        @if (!empty($task['fechaLimite']))
                            <div>
                                <span class="text-gray-500">📅 Fecha límite:</span>
                                <span class="text-gray-900">{{ $task['fechaLimite'] }}</span>
                            </div>
                        @endif
                    </div>

                    <div class="mt-3 pt-3 border-t border-gray-100">
                        @if ($phone)
                            <span
                                class="inline-flex items-center px-2 py-1 text-xs font-medium bg-green-100 text-green-700 rounded">
                                📱 Se enviará a: +{{ $phone }}
                            </span>
                        @else
                            <span
                                class="inline-flex items-center px-2 py-1 text-xs font-medium bg-yellow-100 text-yellow-700 rounded">
                                ⚠️ "{{ $asignado }}" no tiene número configurado
                            </span>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
