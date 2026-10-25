import { Badge } from "@/components/ui/badge";

export const STOCKTAKE_STATUS_LABEL: Record<string, string> = {
    draft: "Draft",
    counting: "Sedang dihitung",
    pending_approval: "Menunggu approval",
    posted: "Diposting",
    cancelled: "Dibatalkan",
};

const VARIANT: Record<
    string,
    "default" | "secondary" | "outline" | "destructive"
> = {
    draft: "secondary",
    counting: "default",
    pending_approval: "outline",
    posted: "default",
    cancelled: "destructive",
};

export function StocktakeStatusBadge({ status }: { status: string }) {
    return (
        <Badge variant={VARIANT[status] ?? "secondary"}>
            {STOCKTAKE_STATUS_LABEL[status] ?? status}
        </Badge>
    );
}
