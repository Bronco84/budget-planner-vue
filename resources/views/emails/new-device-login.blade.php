<x-mail::message>
# New Device Login Detected

Hello {{ $user->name }},

We detected a login to your Budget Planner account from a new device:

**Device:** {{ $device->device_name }}  
**IP Address:** {{ $device->ip_address }}  
**Time:** {{ $device->created_at->format('F j, Y g:i A') }}

This device will be remembered for 90 days. If this wasn't you, please secure your account immediately.

<x-mail::button :url="route('settings.devices')">
Manage Devices
</x-mail::button>

If you don't recognize this activity, click the button above to revoke this device and review your security settings.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>

