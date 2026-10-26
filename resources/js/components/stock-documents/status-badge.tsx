import { Badge } from "@/components/ui/badge";

const LABEL: Record<string, string> = {
    draft: "Draft",
    posted: "Diposting",
    reversed: "Dibalik",
};

const VARIANT: Record<string, "default" | "secondary" | "destructive"> = {
    draft: "secondary",
    posted: "default",
    reversed: "destructive",
};

export function StatusBadge({ status }: { status: string }) {
    return (
        <Badge variant={VARIANT[status] ?? "secondary"}>
            {LABEL[status] ?? status}
        </Badge>
    );
}
