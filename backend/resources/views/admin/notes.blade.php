<x-admin-layout title="Admin Notes" heading="Admin Notes">
    @php
        $pageNotes = $notes->getCollection();
        $pinnedOnPage = $pageNotes->where('is_pinned', true)->count();
        $updatedToday = $pageNotes->filter(fn ($n) => $n->updated_at && $n->updated_at->isToday())->count();
    @endphp

    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="grid gap-4 xl:grid-cols-[1fr,auto] xl:items-end">
            <div>
                <p class="text-xs uppercase tracking-wide text-slate-500">Workspace Notes</p>
                <h3 class="mt-1 text-2xl font-semibold text-slate-900">Admin Notes Center</h3>
                <p class="mt-1 text-sm text-slate-600">Capture operational context, deployment reminders, and incident handover details in one place.</p>
            </div>
            <div class="grid grid-cols-3 gap-2">
                <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
                    <p class="text-[11px] text-slate-500">Total</p>
                    <p class="text-lg font-semibold text-slate-900">{{ $notes->total() }}</p>
                </div>
                <div class="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2">
                    <p class="text-[11px] text-amber-700">Pinned</p>
                    <p class="text-lg font-semibold text-amber-700">{{ $pinnedOnPage }}</p>
                </div>
                <div class="rounded-xl border border-sky-200 bg-sky-50 px-3 py-2">
                    <p class="text-[11px] text-sky-700">Updated Today</p>
                    <p class="text-lg font-semibold text-sky-700">{{ $updatedToday }}</p>
                </div>
            </div>
        </div>
    </section>

    <section class="mt-4 grid gap-4 xl:grid-cols-[340px,1fr]">
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <h4 class="text-sm font-semibold text-slate-900">Create Note</h4>
            <p class="mt-1 text-xs text-slate-500">Use pinned notes for priority instructions.</p>

            <form method="POST" action="{{ route('admin.notes.create') }}" class="mt-4 grid gap-3">
                @csrf
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Title</label>
                    <input name="title" value="{{ old('title') }}" required maxlength="180" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                    @error('title')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Note Body</label>
                    <textarea name="body" rows="8" required maxlength="20000" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">{{ old('body') }}</textarea>
                    @error('body')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <label class="inline-flex items-center gap-2 text-xs text-slate-700">
                    <input type="checkbox" name="is_pinned" value="1" @checked(old('is_pinned')) class="rounded border-slate-300">
                    Pin this note
                </label>
                <button class="rounded-lg bg-skyline px-3 py-2 text-xs font-medium text-white">Save Note</button>
            </form>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="mb-3 grid gap-2 lg:grid-cols-[auto,1fr,auto] lg:items-center">
                <h4 class="font-semibold text-slate-900">Notes Board</h4>
                <form method="GET" action="{{ route('admin.notes') }}" class="flex items-center gap-2">
                    <input type="text" name="q" value="{{ $searchQuery ?? '' }}" placeholder="Search title or note content..." class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-xs" />
                    <button class="rounded-lg bg-ink px-3 py-1.5 text-xs font-medium text-white">Search</button>
                    @if(!empty($searchQuery))
                        <a href="{{ route('admin.notes') }}" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs text-slate-700">Clear</a>
                    @endif
                </form>
                <p class="text-xs text-slate-500 lg:text-right">Showing {{ $notes->count() }} / {{ $notes->total() }}</p>
            </div>

            <div class="grid gap-3 md:grid-cols-2 2xl:grid-cols-3">
                @forelse($notes as $note)
                    <article class="rounded-xl border {{ $note->is_pinned ? 'border-amber-300 bg-amber-50/30' : 'border-slate-200 bg-slate-50/40' }} p-3">
                        <div class="flex items-start justify-between gap-2">
                            <h5 class="text-sm font-semibold text-slate-900 leading-tight">{{ $note->title }}</h5>
                            @if($note->is_pinned)
                                <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-medium text-amber-700">Pinned</span>
                            @endif
                        </div>
                        <p class="mt-2 line-clamp-6 whitespace-pre-wrap text-xs text-slate-700">{{ $note->body }}</p>
                        <p class="mt-2 text-[11px] text-slate-500">
                            By {{ $note->author?->name ?? 'Unknown' }} | {{ $note->updated_at?->format('Y-m-d H:i') }}
                        </p>

                        <details class="mt-2 rounded-md border border-slate-200 bg-white">
                            <summary class="cursor-pointer px-2 py-1.5 text-[11px] font-medium text-slate-600">Edit</summary>
                            <form method="POST" action="{{ route('admin.notes.update', $note->id) }}" class="grid gap-2 border-t border-slate-200 px-2 py-2">
                                @csrf
                                @method('PATCH')
                                <input name="title" value="{{ $note->title }}" maxlength="180" required class="w-full rounded-md border border-slate-300 px-2 py-1 text-xs" />
                                <textarea name="body" rows="5" maxlength="20000" required class="w-full rounded-md border border-slate-300 px-2 py-1 text-xs">{{ $note->body }}</textarea>
                                <label class="inline-flex items-center gap-2 text-[11px] text-slate-700">
                                    <input type="checkbox" name="is_pinned" value="1" @checked($note->is_pinned) class="rounded border-slate-300">
                                    Pin this note
                                </label>
                                <button class="rounded-md bg-ink px-2.5 py-1 text-[11px] font-medium text-white">Update</button>
                            </form>
                        </details>

                        <form method="POST" action="{{ route('admin.notes.delete', $note->id) }}" class="mt-2" onsubmit="return confirm('Delete this note?');">
                            @csrf
                            @method('DELETE')
                            <button class="rounded-md border border-red-300 px-2.5 py-1 text-[11px] font-medium text-red-700 hover:bg-red-50">Delete</button>
                        </form>
                    </article>
                @empty
                    <div class="col-span-full rounded-xl border border-dashed border-slate-300 bg-slate-50 p-10 text-center">
                        <p class="text-sm font-medium text-slate-700">No notes yet</p>
                        <p class="mt-1 text-xs text-slate-500">Create your first admin note from the left panel.</p>
                    </div>
                @endforelse
            </div>

            <div class="mt-5">{{ $notes->links() }}</div>
        </div>
    </section>
</x-admin-layout>
