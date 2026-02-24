<x-admin-layout title="Policy Categories" heading="Policy Categories">
    <div class="rounded-2xl bg-white border border-slate-200 p-4 space-y-3">
        <h3 class="font-semibold">Add Category</h3>
        <p class="text-xs text-slate-600">Use clear category names like <span class="font-mono">security/device_control</span>.</p>
        <form method="POST" action="{{ route('admin.policies.categories.create') }}" class="flex items-center gap-2">
            @csrf
            <input name="category" placeholder="new/category" required class="flex-1 rounded border border-slate-300 px-2 py-2 text-sm"/>
            <button class="rounded bg-ink text-white px-3 py-2 text-xs">Add</button>
        </form>

        <div class="rounded-lg border border-slate-200 overflow-hidden">
            <div class="px-3 py-2 bg-slate-50 text-xs text-slate-600">
                Category usage helps you decide delete/replace safely.
            </div>
            <div class="max-h-[34rem] overflow-auto">
                @forelse($categoryStats as $row)
                    <div class="border-t border-slate-200 p-3 space-y-2">
                        <div class="flex items-center justify-between gap-2">
                            <p class="font-mono text-xs">{{ $row['name'] }}</p>
                            <p class="text-[11px] text-slate-500">Policies: {{ $row['policy_count'] }} | Presets: {{ $row['preset_count'] }}</p>
                        </div>
                        <form method="POST" action="{{ route('admin.policies.categories.update') }}" class="flex items-center gap-2">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="current_category" value="{{ $row['name'] }}">
                            <input name="new_category" value="{{ $row['name'] }}" required class="flex-1 rounded border border-slate-300 px-2 py-1 text-xs"/>
                            <button class="rounded bg-slate-700 text-white px-2 py-1 text-xs">Rename</button>
                        </form>
                        <form method="POST" action="{{ route('admin.policies.categories.delete') }}" class="flex items-center gap-2">
                            @csrf
                            @method('DELETE')
                            <input type="hidden" name="category" value="{{ $row['name'] }}">
                            <select name="replace_with" class="flex-1 rounded border border-slate-300 px-2 py-1 text-xs">
                                <option value="">Delete without replacement</option>
                                @foreach(($policyCategories ?? []) as $opt)
                                    @if($opt !== $row['name'])
                                        <option value="{{ $opt }}">{{ $opt }}</option>
                                    @endif
                                @endforeach
                            </select>
                            <button class="rounded bg-red-600 text-white px-2 py-1 text-xs">Delete</button>
                        </form>
                    </div>
                @empty
                    <p class="p-3 text-sm text-slate-500">No categories found.</p>
                @endforelse
            </div>
        </div>
    </div>
</x-admin-layout>
