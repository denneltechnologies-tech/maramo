<?php

namespace Classivo\Hr\Filament\Resources\Staff\Actions;

use Classivo\Hr\Models\Staff;
use Classivo\Hr\Support\HrPermissions;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Support\Icons\Heroicon;

/**
 * "Documents" header menu for the staff view/edit pages: one Download link per
 * stored file, pointing at the authorised `hr.staff-documents.download` route.
 * Visible only to `hr.staff.view` holders or the staff member themself.
 */
final class DocumentDownloadActions
{
    public static function authorised(Staff $staff): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        return HrPermissions::can(HrPermissions::STAFF_VIEW)
            || ($staff->user_id !== null && (int) $staff->user_id === (int) $user->getAuthIdentifier());
    }

    public static function make(Staff $staff): ActionGroup
    {
        $actions = $staff->documents()
            ->whereNotNull('file_path')
            ->where('file_path', '!=', '')
            ->get()
            ->map(fn ($doc): Action => Action::make('download_document_'.$doc->id)
                ->label(__('hr::messages.staff.download_document', ['title' => $doc->title]))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->url(route('hr.staff-documents.download', $doc))
                ->openUrlInNewTab()
                ->visible(fn (): bool => self::authorised($staff)))
            ->all();

        return ActionGroup::make($actions)
            ->label(__('hr::ui.documents'))
            ->icon(Heroicon::OutlinedPaperClip)
            ->button()
            ->color('gray')
            ->visible(fn (): bool => $actions !== [] && self::authorised($staff));
    }
}
