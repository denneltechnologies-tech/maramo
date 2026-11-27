import { defineConfig } from 'vitepress'

const pkgs = [
  'core',
  'exams',
  'fees',
  'grading',
  'hr',
  'report-cards',
  'students',
  'timetable',
]

export default defineConfig({
  title: 'Classivo ERP',
  description: 'A complete school management kit built with Laravel and Filament',
  cleanUrls: true,
  themeConfig: {
    search: { provider: 'local' },
    nav: [
      { text: 'Features', link: '/features' },
      { text: 'Demo', link: '/demo' },
      { text: 'Website', link: 'https://classivoerp.com' }
    ],
    sidebar: [
      {
        text: 'Guide',
        items: [
          { text: 'Overview', link: '/' },
          { text: 'Install', link: '/install' },
          { text: 'Architecture', link: '/architecture' },
          { text: 'Features', link: '/features' },
          { text: 'Demo', link: '/demo' }
        ]
      },
      {
        text: 'Packages',
        items: pkgs.map((n) => ({ text: n, link: `/packages/${n}` }))
      }
    ]
  }
})
