import { router } from "@inertiajs/react";
import { useState } from "react";

import { Button } from "@/components/ui/button";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import { Label } from "@/components/ui/label";

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    url: string;
    number: string;
    effect: string;
};

export function ReverseDialog({
    open,
    onOpenChange,
    url,
    number,
    effect,
}: Props) {
    const [reason, setReason] = useState("");
    const [error, setError] = useState<string | undefined>();
    const [busy, setBusy] = useState(false);

    function submit() {
        setBusy(true);
        setError(undefined);

        router.post(
            url,
            { reason },
            {
                preserveScroll: true,
                onSuccess: () => {
                    onOpenChange(false);
                    setReason("");
                },
                onError: (e) => setError(e.reason ?? Object.values(e)[0]),
                onFinish: () => setBusy(false),
            }
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Balik {number}?</DialogTitle>
                    <DialogDescription>
                        Pembalikan akan {effect}. Dokumen asli tetap tersimpan
                        dan ditandai "Dibalik". Tindakan ini tidak dapat
                        dibatalkan dan hanya bisa dilakukan sekali.
                    </DialogDescription>
                </DialogHeader>

                <div className="grid gap-2">
                    <Label htmlFor="reverse-reason">Alasan pembalikan</Label>
                    <textarea
                        id="reverse-reason"
                        rows={3}
                        maxLength={255}
                        value={reason}
                        onChange={(e) => setReason(e.target.value)}
                        className="rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    />
                    {error && (
                        <p className="text-sm text-destructive">{error}</p>
                    )}
                </div>

                <DialogFooter>
                    <Button
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Batal
                    </Button>
                    <Button
                        variant="destructive"
                        onClick={submit}
                        disabled={busy || reason.trim() === ""}
                    >
                        {busy ? "Memproses..." : "Ya, balik dokumen"}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
