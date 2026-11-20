import { PageSkeleton } from './components/ui';
import type { Metadata } from "next";
import { Suspense } from "react";
import { Geist, Geist_Mono } from "next/font/google";
import { AuthProvider } from "./context/AuthContext";
import RoleAccessGate from "./components/RoleAccessGate";
import ServiceWorkerRegistration from "./components/ServiceWorkerRegistration";
import "./globals.css";

const geistSans = Geist({
  variable: "--font-geist-sans",
  subsets: ["latin"],
});

const geistMono = Geist_Mono({
  variable: "--font-geist-mono",
  subsets: ["latin"],
});

export const metadata: Metadata = {
  title: "Clinique+ | Gestion clinique",
  description: "Un espace de travail simple et sécurisé pour organiser votre clinique.",
  manifest: "/manifest.webmanifest",
  applicationName: "Clinique+",
  appleWebApp: { capable: true, title: "Clinique+", statusBarStyle: "default" },
  icons: { icon: "/icons/icon-192.png", apple: "/icons/icon-192.png" },
};

export const viewport = { themeColor: "#047857" };

export default function RootLayout({ children }: LayoutProps<"/">) {
  const className = `${geistSans.variable} ${geistMono.variable} h-full antialiased`;

  return (
    <html lang="fr" className={className} data-scroll-behavior="smooth">
      <body className="min-h-full flex flex-col">
        <ServiceWorkerRegistration />
        <AuthProvider>
          <Suspense fallback={<PageSkeleton />}>
            <RoleAccessGate>{children}</RoleAccessGate>
          </Suspense>
        </AuthProvider>
      </body>
    </html>
  );
}
