import { Badge } from "@/components/ui/badge";

export const TRANSFER_STATUS_LABEL: Record<string, string> = {
    draft: "Draft",
    pending_approval: "Menunggu approval",
    approved: "Disetujui",
    in_transit: "Dalam perjalanan",
    partially_received: "Diterima sebagian",
    received: "Diterima",
    cancelled: "Dibatalkan",
};

const VARIANT: Record<
    string,
    "default" | "secondary" | "outline" | "destructive"
> = {
    draft: "secondary",
    pending_approval: "outline",
    approved: "outline",
    in_transit: "default",
    partially_received: "default",
    received: "default",
    cancelled: "destructive",
};

export function TransferStatusBadge({ status }: { status: string }) {
    return (
        <Badge variant={VARIANT[status] ?? "secondary"}>
            {TRANSFER_STATUS_LABEL[status] ?? status}
        </Badge>
    );
}
