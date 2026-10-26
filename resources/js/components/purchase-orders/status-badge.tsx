import { Badge } from "@/components/ui/badge";

export const PO_STATUS_LABEL: Record<string, string> = {
    draft: "Draft",
    submitted: "Menunggu approval",
    approved: "Disetujui",
    partially_received: "Diterima sebagian",
    received: "Diterima",
    cancelled: "Dibatalkan",
};

const VARIANT: Record<
    string,
    "default" | "secondary" | "outline" | "destructive"
> = {
    draft: "secondary",
    submitted: "outline",
    approved: "outline",
    partially_received: "default",
    received: "default",
    cancelled: "destructive",
};

export function PurchaseOrderStatusBadge({ status }: { status: string }) {
    return (
        <Badge variant={VARIANT[status] ?? "secondary"}>
            {PO_STATUS_LABEL[status] ?? status}
        </Badge>
    );
}
