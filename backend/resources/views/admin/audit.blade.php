<x-admin-layout title="Audit" heading="Immutable Audit Trail">
    <div class="rounded-2xl bg-white border border-slate-200 p-4 overflow-x-auto">
        <h3 class="font-semibold mb-3">Audit Events</h3>
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b text-left text-slate-500">
                    <th class="py-2">#</th>
                    <th class="py-2">Action</th>
                    <th class="py-2">Entity</th>
                    <th class="py-2">Actor User</th>
                    <th class="py-2">Actor Device</th>
                    <th class="py-2">When</th>
                </tr>
            </thead>
            <tbody>
            @foreach($logs as $log)
                <tr class="border-b align-top">
                    <td class="py-2 font-mono text-xs">{{ $log->id }}</td>
                    <td class="py-2">{{ $log->action }}</td>
                    <td class="py-2">
                        <p>{{ $log->entity_type }}</p>
                        <p class="font-mono text-xs text-slate-500">{{ $log->entity_id }}</p>
                    </td>
                    <td class="py-2">{{ $log->actor_user_id }}</td>
                    <td class="py-2">{{ $log->actor_device_id }}</td>
                    <td class="py-2 text-xs">{{ $log->created_at }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <div class="mt-4">{{ $logs->links() }}</div>
    </div>
</x-admin-layout>
