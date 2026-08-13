<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Instance-owned framework-pack catalogue with deterministic crosswalk inspection. */
final class PanelComplianceFrameworkCatalog implements \JsonSerializable {
	/** @var array<string,PanelComplianceFrameworkPack> */ private array $packs=[];
	private int $revision=0;

	public function register(PanelComplianceFrameworkPack $pack,bool $replace=false):self {
		$id=$pack->id();$existing=$this->packs[$id]??null;
		if($existing instanceof PanelComplianceFrameworkPack&&hash_equals($existing->fingerprint(),$pack->fingerprint())){return$this;}
		if($existing instanceof PanelComplianceFrameworkPack&&!$replace){throw new \LogicException('Compliance framework pack id is already registered with a different fingerprint.');}
		$this->packs[$id]=$pack;ksort($this->packs,SORT_STRING);$this->revision++;return$this;
	}

	public function has(string $id):bool{return isset($this->packs[PanelOperationsGuard::name($id,'compliance framework id')]);}
	public function get(string $id):PanelComplianceFrameworkPack {$id=PanelOperationsGuard::name($id,'compliance framework id');$pack=$this->packs[$id]??null;if(!$pack instanceof PanelComplianceFrameworkPack){throw new \OutOfBoundsException('Compliance framework pack is not registered.');}return$pack;}
	/** @return array<string,PanelComplianceFrameworkPack> */ public function packs():array{return$this->packs;}
	public function revision():int{return$this->revision;}
	public function fingerprint():string {$pins=[];foreach($this->packs as$id=>$pack){$pins[$id]=$pack->fingerprint();}return PanelOperationsGuard::digest($pins);}

	/** @return list<array<string,mixed>> */
	public function crosswalks(string $frameworkId,string $controlId):array {
		$pack=$this->get($frameworkId);$control=$pack->control($controlId);if(!is_array($control)){throw new \OutOfBoundsException('Compliance framework control is not registered.');}
		$result=[];foreach($control['crosswalks']as$mapping){$target=$this->packs[$mapping['framework']]??null;$known=$target instanceof PanelComplianceFrameworkPack&&$target->control($mapping['control'])!==null;$result[]=$mapping+['known_target'=>$known,'equivalence_claimed'=>$mapping['relation']==='equivalent'];}return$result;
	}

	/** @return list<array<string,mixed>> */ public function danglingCrosswalks():array {
		$issues=[];foreach($this->packs as$pack){foreach($pack->controls()as$control){foreach($control['crosswalks']as$mapping){$target=$this->packs[$mapping['framework']]??null;if(!$target instanceof PanelComplianceFrameworkPack||$target->control($mapping['control'])===null){$issues[]=['framework'=>$pack->id(),'control'=>$control['id'],'target_framework'=>$mapping['framework'],'target_control'=>$mapping['control'],'relation'=>$mapping['relation']];}}}}return$issues;
	}

	/** Curated, reference-only profiles. They are not certification checklists. */
	public static function firstParty():self {
		$checked='2026-07-16T00:00:00Z';$catalog=new self();
		$nist=[];
		foreach([
			'govern'=>['Governance evidence','NIST CSF 2.0 GV',['governance','risk']],
			'identify'=>['Asset and risk evidence','NIST CSF 2.0 ID',['inventory','risk']],
			'protect'=>['Protective-control evidence','NIST CSF 2.0 PR',['access','data_protection']],
			'detect'=>['Detection evidence','NIST CSF 2.0 DE',['monitoring','detection']],
			'respond'=>['Response evidence','NIST CSF 2.0 RS',['incident_response']],
			'recover'=>['Recovery evidence','NIST CSF 2.0 RC',['continuity','recovery']],
		]as$id=>$item){$nist[$id]=['title'=>$item[0],'references'=>[$item[1]],'domains'=>$item[2],'evidence_requirements'=>['Host-defined evidence mapped to the cited function']];}
		$nist['protect']['crosswalks']=[['framework'=>'gdpr','control'=>'article_32','relation'=>'related'],['framework'=>'hipaa_security_rule','control'=>'section_164_312','relation'=>'related'],['framework'=>'pci_dss','control'=>'requirement_7','relation'=>'related']];
		$nist['respond']['crosswalks']=[['framework'=>'gdpr','control'=>'article_33','relation'=>'related']];
		$catalog->register(PanelComplianceFrameworkPack::make('nist_csf_2','2.0','NIST Cybersecurity Framework 2.0 reference profile','https://tsapps.nist.gov/publication/get_pdf.cfm?pub_id=957258',$nist,['source_checked_at'=>$checked,'coverage_scope'=>'reference_profile','metadata'=>['publisher'=>'NIST','profile_note'=>'Function-level evidence references only']]));

		$gdpr=[];foreach([
			'article_5'=>['Processing-principle evidence','GDPR Article 5',['privacy_governance']],
			'article_25'=>['Design and default safeguards evidence','GDPR Article 25',['privacy_engineering']],
			'article_30'=>['Processing-record evidence','GDPR Article 30',['records']],
			'article_32'=>['Processing-security evidence','GDPR Article 32',['security']],
			'article_33'=>['Supervisory-notification evidence','GDPR Article 33',['incident_response']],
			'article_35'=>['Impact-assessment evidence','GDPR Article 35',['risk','privacy_governance']],
		]as$id=>$item){$gdpr[$id]=['title'=>$item[0],'references'=>[$item[1]],'domains'=>$item[2],'evidence_requirements'=>['Host-defined evidence mapped to the cited article']];}
		$gdpr['article_32']['crosswalks']=[['framework'=>'nist_csf_2','control'=>'protect','relation'=>'related'],['framework'=>'hipaa_security_rule','control'=>'section_164_312','relation'=>'related']];
		$gdpr['article_33']['crosswalks']=[['framework'=>'nist_csf_2','control'=>'respond','relation'=>'related']];
		$catalog->register(PanelComplianceFrameworkPack::make('gdpr','2016/679','GDPR operational evidence reference profile','https://eur-lex.europa.eu/eli/reg/2016/679/oj/eng',$gdpr,['source_checked_at'=>$checked,'coverage_scope'=>'reference_profile','metadata'=>['publisher'=>'European Union','profile_note'=>'Selected operational article references only']]));

		$hipaa=[];foreach([
			'section_164_308'=>['Administrative-safeguard evidence','45 CFR 164.308',['administrative']],
			'section_164_310'=>['Physical-safeguard evidence','45 CFR 164.310',['physical']],
			'section_164_312'=>['Technical-safeguard evidence','45 CFR 164.312',['technical']],
			'section_164_314'=>['Organizational-requirement evidence','45 CFR 164.314',['organizational']],
			'section_164_316'=>['Policy and documentation evidence','45 CFR 164.316',['records','governance']],
		]as$id=>$item){$hipaa[$id]=['title'=>$item[0],'references'=>[$item[1]],'domains'=>$item[2],'evidence_requirements'=>['Host-defined evidence mapped to the cited section']];}
		$hipaa['section_164_312']['crosswalks']=[['framework'=>'nist_csf_2','control'=>'protect','relation'=>'related'],['framework'=>'gdpr','control'=>'article_32','relation'=>'related']];
		$catalog->register(PanelComplianceFrameworkPack::make('hipaa_security_rule','45 CFR 160/164','HIPAA Security Rule evidence reference profile','https://www.hhs.gov/hipaa/for-professionals/security/laws-regulations/index.html',$hipaa,['source_checked_at'=>$checked,'coverage_scope'=>'reference_profile','metadata'=>['publisher'=>'U.S. HHS','profile_note'=>'Safeguard-section references only']]));

		$pci=[];$titles=[1=>'Network-security evidence',2=>'Secure-configuration evidence',3=>'Stored-account-data evidence',4=>'Transmission-protection evidence',5=>'Malware-protection evidence',6=>'Software-security evidence',7=>'Access-restriction evidence',8=>'Identity and authentication evidence',9=>'Physical-access evidence',10=>'Logging and monitoring evidence',11=>'Security-testing evidence',12=>'Security-program evidence'];
		foreach($titles as$number=>$title){$pci['requirement_'.$number]=['title'=>$title,'references'=>['PCI DSS v4.0.1 Requirement '.$number],'domains'=>['payment_security'],'evidence_requirements'=>['Host-defined evidence mapped to the cited requirement']];}
		$pci['requirement_7']['crosswalks']=[['framework'=>'nist_csf_2','control'=>'protect','relation'=>'related'],['framework'=>'hipaa_security_rule','control'=>'section_164_312','relation'=>'related']];
		$catalog->register(PanelComplianceFrameworkPack::make('pci_dss','4.0.1','PCI DSS 4.0.1 evidence reference profile','https://www.pcisecuritystandards.org/document_library/?class=pcidss&doc=pci_dss',$pci,['source_checked_at'=>$checked,'coverage_scope'=>'reference_profile','metadata'=>['publisher'=>'PCI Security Standards Council','profile_note'=>'Top-level requirement references only']]));
		return$catalog;
	}

	/** @return array<string,mixed> */ public function jsonSerialize():array {$packs=[];foreach($this->packs as$id=>$pack){$packs[$id]=$pack->jsonSerialize();}return PanelManifestContract::stamp([
		'type'=>'panel_compliance_framework_catalog','version'=>1,'revision'=>$this->revision,'pack_count'=>count($packs),
		'control_count'=>array_sum(array_map(static fn(PanelComplianceFrameworkPack $pack):int=>count($pack->controls()),$this->packs)),
		'fingerprint'=>$this->fingerprint(),'packs'=>$packs,'dangling_crosswalks'=>$this->danglingCrosswalks(),
		'claims'=>['certification'=>false,'legal_advice'=>false,'crosswalk_equivalence'=>false],
	]);}
}
