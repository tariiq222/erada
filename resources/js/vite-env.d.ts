/// <reference types="vite/client" />

import "@tabler/icons-react";
import type * as React from "react";

declare global {
  interface ImportMetaEnv {
    readonly DEV: boolean;
    readonly PROD: boolean;
    readonly MODE: string;
    readonly BASE_URL: string;
    readonly VITE_APP_TITLE?: string;
  }

  interface ImportMeta {
    readonly env: ImportMetaEnv;
  }
}

/* Augment @tabler/icons-react to expose the LucideIcon component type.
   The shim previously re-exported this type; tabler itself only exports
   `Icon*` components, so we declare the alias here. */
declare module "@tabler/icons-react" {
  export type LucideIcon = React.ForwardRefExoticComponent<
    React.RefAttributes<globalThis.SVGSVGElement> & {
      className?: string;
      style?: React.CSSProperties;
      size?: number | string;
      stroke?: number | string;
      strokeWidth?: number | string;
      color?: string;
      fill?: string;
    }
  >;
}
