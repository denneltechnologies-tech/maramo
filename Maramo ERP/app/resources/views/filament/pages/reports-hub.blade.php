<x-filament-panels::page>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <!-- 1. Student Roster -->
        <div class="p-6 bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 flex flex-col justify-between">
            <div>
                <div class="w-10 h-10 rounded-lg bg-amber-50 dark:bg-amber-950 flex items-center justify-center text-amber-600 mb-4">
                    <x-heroicon-o-academic-cap class="w-6 h-6" />
                </div>
                <h3 class="text-base font-semibold text-gray-950 dark:text-white">Student Registration Report</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Full roster of enrolled students with contact details, courses, and statuses.</p>
            </div>
            <div class="mt-6">
                <x-filament::button wire:click="exportStudents" icon="heroicon-o-arrow-down-tray" color="primary" class="w-full">
                    Download CSV (Excel)
                </x-filament::button>
            </div>
        </div>

        <!-- 2. Admissions & Applications -->
        <div class="p-6 bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 flex flex-col justify-between">
            <div>
                <div class="w-10 h-10 rounded-lg bg-blue-50 dark:bg-blue-950 flex items-center justify-center text-blue-600 mb-4">
                    <x-heroicon-o-clipboard-document-list class="w-6 h-6" />
                </div>
                <h3 class="text-base font-semibold text-gray-950 dark:text-white">Admissions & Applications Report</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Website and walk-in application logs with review and approval status.</p>
            </div>
            <div class="mt-6">
                <x-filament::button wire:click="exportApplications" icon="heroicon-o-arrow-down-tray" color="gray" class="w-full">
                    Download CSV (Excel)
                </x-filament::button>
            </div>
        </div>

        <!-- 3. Payments & Revenue -->
        <div class="p-6 bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 flex flex-col justify-between">
            <div>
                <div class="w-10 h-10 rounded-lg bg-green-50 dark:bg-green-950 flex items-center justify-center text-green-600 mb-4">
                    <x-heroicon-o-banknotes class="w-6 h-6" />
                </div>
                <h3 class="text-base font-semibold text-gray-950 dark:text-white">Student Payment Transactions</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">All collected payments with receipt numbers, Mobile Money references, and cashier logs.</p>
            </div>
            <div class="mt-6">
                <x-filament::button wire:click="exportPayments" icon="heroicon-o-arrow-down-tray" color="success" class="w-full">
                    Download CSV (Excel)
                </x-filament::button>
            </div>
        </div>

        <!-- 4. Outstanding Fees / Defaulters -->
        <div class="p-6 bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 flex flex-col justify-between">
            <div>
                <div class="w-10 h-10 rounded-lg bg-rose-50 dark:bg-rose-950 flex items-center justify-center text-rose-600 mb-4">
                    <x-heroicon-o-exclamation-triangle class="w-6 h-6" />
                </div>
                <h3 class="text-base font-semibold text-gray-950 dark:text-white">Outstanding Fees & Balances</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Detailed list of students with unpaid fee balances, due dates, and phone numbers for follow-up.</p>
            </div>
            <div class="mt-6">
                <x-filament::button wire:click="exportOutstandingFees" icon="heroicon-o-arrow-down-tray" color="danger" class="w-full">
                    Download CSV (Excel)
                </x-filament::button>
            </div>
        </div>

        <!-- 5. Certificates Issued Register -->
        <div class="p-6 bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 flex flex-col justify-between">
            <div>
                <div class="w-10 h-10 rounded-lg bg-purple-50 dark:bg-purple-950 flex items-center justify-center text-purple-600 mb-4">
                    <x-heroicon-o-check-badge class="w-6 h-6" />
                </div>
                <h3 class="text-base font-semibold text-gray-950 dark:text-white">Certificate Issuance Register</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Complete database of all issued professional certificates with serial numbers and dates.</p>
            </div>
            <div class="mt-6">
                <x-filament::button wire:click="exportCertificates" icon="heroicon-o-arrow-down-tray" color="gray" class="w-full">
                    Download CSV (Excel)
                </x-filament::button>
            </div>
        </div>
    </div>
</x-filament-panels::page>
