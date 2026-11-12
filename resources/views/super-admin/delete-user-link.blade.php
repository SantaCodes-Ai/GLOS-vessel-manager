@if(auth()->user()->isAdmin() && in_array($deletionTarget->role, ['admin', 'client', 'super_admin'], true) && auth()->id() !== $deletionTarget->id)
    <a class="vessel-button delete-user-button" href="{{ route('super-admin.users.delete', $deletionTarget) }}">Delete User</a>
@endif
