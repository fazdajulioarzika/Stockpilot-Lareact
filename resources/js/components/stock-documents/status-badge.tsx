import { Badge } from "@/components/ui/badge";

export function StatusBadge({ status }: { status: string }) {
    const posted = status === "posted";

    return (
        <Badge variant={posted ? "default" : "secondary"}>
            {posted ? "Diposting" : "Draft"}
        </Badge>
    );
}
