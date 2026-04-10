<div class="rounded-2xl border border-slate-200 bg-white p-3">
    <nav class="flex flex-wrap gap-2 text-sm" aria-label="Asset Management Navigation">
        <a href="{{ route('admin.assets') }}" class="rounded-lg border px-3 py-1.5 {{ request()->routeIs('admin.assets') ? 'border-skyline bg-skyline text-white' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50' }}">Overview</a>
        <a href="{{ route('admin.assets.hardware') }}" class="rounded-lg border px-3 py-1.5 {{ request()->routeIs('admin.assets.hardware') ? 'border-skyline bg-skyline text-white' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50' }}">Hardware Inventory</a>
        <a href="{{ route('admin.assets.software') }}" class="rounded-lg border px-3 py-1.5 {{ request()->routeIs('admin.assets.software') ? 'border-skyline bg-skyline text-white' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50' }}">Software Inventory</a>
        <a href="{{ route('admin.assets.clients') }}" class="rounded-lg border px-3 py-1.5 {{ request()->routeIs('admin.assets.clients') ? 'border-skyline bg-skyline text-white' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50' }}">Client Management</a>
    </nav>
</div>
