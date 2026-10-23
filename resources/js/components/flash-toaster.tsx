import { usePage } from "@inertiajs/react";
import { useEffect } from "react";
import { toast } from "sonner";

type Flash = { success?: string | null; error?: string | null };

export function FlashToaster() {
    const { flash } = usePage<{ flash?: Flash }>().props;

    useEffect(() => {
        if (flash?.success) toast.success(flash.success);
        if (flash?.error) toast.error(flash.error);
    }, [flash]);

    return null;
}
